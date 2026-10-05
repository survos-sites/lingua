<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Target;
use App\Message\TranslateBatchMessage;
use App\Message\TranslationWorker\TranslationJobRequest;
use App\Repository\TargetRepository;
use App\Service\TargetTranslationApplier;
use App\Service\TranslationEngineCatalog;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Service\TranslatorManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Target as WorkflowTarget;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * One HTTP call per language pair, instead of one per string.
 *
 * See {@see TranslateBatchMessage} for why the batch is formed at dispatch time rather than
 * with Symfony's BatchHandlerInterface.
 *
 * Writes go through {@see TargetTranslationApplier} — the same call
 * `TargetWorkflow::onTransition` makes for a single Target — so the batched path and the
 * per-message path cannot decide "translated vs identical" differently.
 */
#[AsMessageHandler]
final class TranslateBatchMessageHandler
{
    /** Spoke locales one hub batch may dispatch. Guards against a runaway fan-out. */
    private const int MAX_FAN_OUT = 25;

    public function __construct(
        private readonly TargetRepository $targetRepository,
        private readonly TranslatorManager $translators,
        private readonly TargetTranslationApplier $applier,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly TranslationEngineCatalog $engineCatalog,
        #[WorkflowTarget(TargetWorkflowInterface::WORKFLOW_NAME)] private readonly WorkflowInterface $targetWorkflow,
    ) {
    }

    public function __invoke(TranslateBatchMessage $message): void
    {
        if ($message->targetKeys === []) {
            return;
        }

        if ($this->engineCatalog->isWorker($message->engine)) {
            $this->dispatchToWorker($message);

            return;
        }

        $translator = $this->translators->by($message->engine);
        if (!$translator) {
            // Unrecoverable: an engine that does not exist will not start existing on the
            // third attempt. Retrying would burn the worker and hide the misconfiguration.
            throw new UnrecoverableMessageHandlingException(\sprintf(
                'Unknown engine "%s". Configured: %s.',
                $message->engine,
                implode(', ', $this->translators->names()),
            ));
        }

        /** @var Target[] $targets */
        $targets = $this->targetRepository->findBy(['key' => $message->targetKeys]);

        // Position matters: the engine returns translations as a positional array, so the
        // texts we send and the targets we write back to must stay in lockstep. Building both
        // lists in one pass is what guarantees that — never re-derive one from the other.
        $texts = [];
        $pending = [];

        // Spoke leg: the input is the already-stored hub translation, not the source text.
        // Resolved in ONE query for the whole batch — a findOneBy() per target would undo the
        // point of batching.
        $pivotTexts = $message->pivotLocale === null ? [] : $this->pivotTexts($targets, $message);

        foreach ($targets as $target) {
            if ($target->isTranslated || $target->isIdentical) {
                // Already done — a redelivery, or another batch got there first. Skipping is
                // free; re-translating would cost an engine call and could overwrite a
                // human-reviewed string.
                continue;
            }

            if ($message->pivotLocale !== null) {
                // No hub text yet: the hub leg failed, or this spoke overtook it. Skip rather
                // than fall back to the source text — falling back would silently produce a
                // direct translation while recording pivotLocale, i.e. a lie in the data.
                // The row stays untranslated and is picked up by the next push.
                $sourceText = $pivotTexts[(string) $target->key] ?? null;
            } else {
                $sourceText = $target->source?->getText();
            }

            if ($sourceText === null || $sourceText === '') {
                continue;
            }

            $texts[] = $sourceText;
            $pending[] = $target;
        }

        if ($pending === []) {
            return;
        }

        $result = $translator->translateBatch(new TranslationBatchRequest(
            texts: $texts,
            source: $message->sourceLocale,
            target: $message->targetLocale,
        ));

        $translations = $result->translatedTexts;

        if (\count($translations) !== \count($pending)) {
            // A positional contract that does not line up is not something to paper over by
            // applying what did arrive — that would silently attach translations to the wrong
            // strings. Fail loudly and let the retry re-send the whole group.
            throw new \RuntimeException(\sprintf(
                'Engine "%s" returned %d translations for %d texts (%s->%s).',
                $message->engine,
                \count($translations),
                \count($pending),
                $message->sourceLocale,
                $message->targetLocale,
            ));
        }

        $applied = 0;
        foreach ($pending as $i => $target) {
            if ($this->applier->apply($target, (string) $translations[$i], $message->pivotLocale, $result->meta)) {
                $applied++;
            }
        }

        // Once for the whole batch. This, and the single HTTP call above, are the entire point.
        $this->em->flush();

        $this->logger->info('translate batch [{from}->{to}]{via} {applied}/{requested} via {engine}', [
            'from' => $message->sourceLocale,
            'to' => $message->targetLocale,
            'via' => $message->pivotLocale ? ' (pivot ' . $message->pivotLocale . ')' : '',
            'applied' => $applied,
            'requested' => \count($message->targetKeys),
            'engine' => $message->engine,
        ]);

        $this->fanOut($message, $pending);
    }

    /**
     * Hub text for each target in this batch, keyed by the SPOKE target's key.
     *
     * `Target::calcKey()` is deterministic — (sourceHash, locale, engine) — so the hub row's
     * key is computable from the spoke row without a join, which keeps this to one IN() query
     * for the whole batch.
     *
     * @param Target[] $targets
     *
     * @return array<string,string> spoke target key => hub text
     */
    private function pivotTexts(array $targets, \App\Message\TranslateBatchMessage $message): array
    {
        $hubKeyBySpokeKey = [];
        foreach ($targets as $target) {
            if (!$target->source) {
                continue;
            }
            $hubKeyBySpokeKey[(string) $target->key] = Target::calcKey(
                $target->source,
                $message->pivotLocale,
                $target->engine,
            );
        }

        if ($hubKeyBySpokeKey === []) {
            return [];
        }

        $hubText = [];
        foreach ($this->targetRepository->findBy(['key' => array_values($hubKeyBySpokeKey)]) as $hub) {
            // Only a FINISHED hub counts. An untranslated hub row exists from the moment intake
            // creates it, and its targetText is null.
            if (($hub->isTranslated || $hub->isIdentical) && ($hub->targetText ?? '') !== '') {
                $hubText[(string) $hub->key] = $hub->targetText;
            }
        }

        $out = [];
        foreach ($hubKeyBySpokeKey as $spokeKey => $hubKey) {
            if (isset($hubText[$hubKey])) {
                $out[$spokeKey] = $hubText[$hubKey];
            }
        }

        return $out;
    }

    /**
     * Dispatch the spoke batches this hub batch was translated for.
     *
     * Runs after the flush, so the hub text the spokes will read is already committed.
     *
     * The spoke keys are computed, not queried: intake has already created every Target in the
     * request, and Target::calcKey() is deterministic, so the hub's own rows tell us what the
     * spoke rows are called.
     *
     * @param Target[] $pending targets this batch actually translated
     */
    private function fanOut(\App\Message\TranslateBatchMessage $message, array $pending): void
    {
        if ($message->thenLocales === [] || $pending === []) {
            return;
        }

        if (\count($message->thenLocales) > self::MAX_FAN_OUT) {
            // Bounded deliberately. A handler that dispatches more messages is the shape that
            // buried mediary's queue; refusing loudly beats discovering it at 3am.
            throw new UnrecoverableMessageHandlingException(\sprintf(
                'Refusing to fan out to %d locales (max %d) from %s->%s.',
                \count($message->thenLocales),
                self::MAX_FAN_OUT,
                $message->sourceLocale,
                $message->targetLocale,
            ));
        }

        foreach ($message->thenLocales as $spokeLocale) {
            $keys = [];
            foreach ($pending as $target) {
                if ($target->source) {
                    $keys[] = Target::calcKey($target->source, $spokeLocale, $target->engine);
                }
            }

            if ($keys === []) {
                continue;
            }

            $this->bus->dispatch(new \App\Message\TranslateBatchMessage(
                // The spoke's engine input is the HUB text, so the source locale it is really
                // translating from is the hub locale — not the original source. Recording it
                // honestly here is what lets the engine be given the right language pair.
                sourceLocale: $message->targetLocale,
                targetLocale: $spokeLocale,
                engine: $message->engine,
                targetKeys: $keys,
                pivotLocale: $message->targetLocale,
            ));
        }

        $this->logger->info('hub {from}->{to} complete, fanned out to {locales}', [
            'from' => $message->sourceLocale,
            'to' => $message->targetLocale,
            'locales' => implode(',', $message->thenLocales),
        ]);
    }

    /**
     * Worker profiles (dispatch: worker): the same batch, but the "engine call" is a publish and
     * the answer arrives later, through the results consumer and TargetTranslationApplier like
     * every other engine. `dispatch` (u → q) records that a job is out. request_id is the Target
     * key, so the result maps straight back to its row.
     *
     * Every job is a direct source → locale request, so a hub batch's spokes are sent alongside
     * it rather than waiting for an English that this path never stores first.
     */
    private function dispatchToWorker(TranslateBatchMessage $message): void
    {
        /** @var Target[] $targets */
        $targets = $this->targetRepository->findBy(['key' => $message->targetKeys]);
        foreach ($message->thenLocales as $spokeLocale) {
            $keys = array_map(static fn (Target $t): string => Target::calcKey($t->source, $spokeLocale, $t->engine), array_filter($targets, static fn (Target $t): bool => $t->source !== null));
            array_push($targets, ...$this->targetRepository->findBy(['key' => $keys]));
        }

        $dispatched = 0;
        foreach ($targets as $target) {
            // t/i are done and q is in flight; can() is the whole idempotency check.
            if (!$this->targetWorkflow->can($target, TargetWorkflowInterface::TRANSITION_DISPATCH)) {
                continue;
            }

            // `q` is committed BEFORE the publish, so a fast result always finds it there.
            $this->targetWorkflow->apply($target, TargetWorkflowInterface::TRANSITION_DISPATCH);
            $this->em->flush();

            try {
                // Routed to the send-only `translation_jobs` transport: mandatory + confirmed,
                // so a throw here means the broker did not take it.
                $this->bus->dispatch(new TranslationJobRequest(
                    requestId: (string) $target->key,
                    text: (string) $target->source?->getText(),
                    sourceLocale: (string) $target->source?->locale,
                    targetLocale: (string) $target->targetLocale,
                    profile: $target->engine,
                ));
            } catch (\Throwable $e) {
                // Not sent: back to `u`, and Messenger retries the batch.
                $this->targetWorkflow->apply($target, TargetWorkflowInterface::TRANSITION_REJECT);
                $this->em->flush();
                throw $e;
            }
            $dispatched++;
        }

        $this->logger->info('worker batch [{from}->{to}] {dispatched}/{requested} via {engine}', [
            'from' => $message->sourceLocale,
            'to' => $message->targetLocale,
            'dispatched' => $dispatched,
            'requested' => \count($targets),
            'engine' => $message->engine,
        ]);
    }
}
