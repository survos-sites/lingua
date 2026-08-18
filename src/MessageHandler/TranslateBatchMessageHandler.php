<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Target;
use App\Message\TranslateBatchMessage;
use App\Repository\TargetRepository;
use App\Service\TargetTranslationApplier;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Service\TranslatorManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

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
    public function __construct(
        private readonly TargetRepository $targetRepository,
        private readonly TranslatorManager $translators,
        private readonly TargetTranslationApplier $applier,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TranslateBatchMessage $message): void
    {
        if ($message->targetKeys === []) {
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

        foreach ($targets as $target) {
            if ($target->isTranslated || $target->isIdentical) {
                // Already done — a redelivery, or another batch got there first. Skipping is
                // free; re-translating would cost an engine call and could overwrite a
                // human-reviewed string.
                continue;
            }

            $sourceText = $target->source?->getText();
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
            if ($this->applier->apply($target, (string) $translations[$i])) {
                $applied++;
            }
        }

        // Once for the whole batch. This, and the single HTTP call above, are the entire point.
        $this->em->flush();

        $this->logger->info('translate batch [{from}->{to}] {applied}/{requested} via {engine}', [
            'from' => $message->sourceLocale,
            'to' => $message->targetLocale,
            'applied' => $applied,
            'requested' => \count($message->targetKeys),
            'engine' => $message->engine,
        ]);
    }
}
