<?php

declare(strict_types=1);

namespace App\Service\TranslationWorker;

use App\Entity\Target;
use App\Message\TranslationWorker\TranslationResultMessage;
use App\Service\TargetTranslationApplier;
use App\Service\TranslationEngineCatalog;
use App\Workflow\TargetWorkflowInterface as WF;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Applies one worker result to the Target whose key is its request_id. Idempotent.
 *
 * Writing goes through {@see TargetTranslationApplier}, the same translated vs identical
 * decision as every other engine. Its flush fires TranslationCompletionNotifier, whose
 * FlushTranslationNotificationsMessage is written to a Doctrine transport inside this
 * transaction, so callbacks are scheduled if and only if the result commits.
 *
 * The row is locked FOR UPDATE, so concurrent duplicates serialize: the first applies, and the
 * rest find a translated Target and are acked. A result from an older forced attempt cannot be
 * told apart from the latest (same Target key), and does not need to be: the profile pins
 * model, revision and variant, so either answer is equally valid.
 *
 * Every rejection is unrecoverable and lands in the Doctrine `failed` transport, which is where
 * failed jobs are inspected and retried:
 *   - unknown request_id, or profile/locales that disagree with the Target
 *   - a model/revision/variant that disagrees with the profile (never applied)
 *   - a worker failure or an empty translation: the Target goes back to `u` first, so the
 *     next push sends it again, and no callback fires.
 */
final class TranslationResultProcessor
{
    public const string APPLIED = 'applied';
    public const string DUPLICATE = 'duplicate';
    public const string FAILED = 'failed';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TargetTranslationApplier $applier,
        private readonly TranslationEngineCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(TranslationResultMessage $result): string
    {
        // Not wrapInTransaction(): that closes the EntityManager on any exception, and a
        // rejected result is routine here — the consumer must keep its EM for the next one.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $rejection = null;
            $outcome = $this->apply($result, $rejection);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->em->clear();
            throw $e;
        }

        // Thrown only after the commit, so the Target's return to `u` survives the rejection.
        if ($rejection !== null) {
            throw new UnrecoverableMessageHandlingException($rejection);
        }

        return $outcome;
    }

    private function apply(TranslationResultMessage $result, ?string &$rejection): string
    {
        $target = $this->em->find(Target::class, $result->requestId, LockMode::PESSIMISTIC_WRITE);
        if ($target === null) {
            throw new UnrecoverableMessageHandlingException('No Target for translation request_id '.$result->requestId.'.');
        }
        // FOR UPDATE does not refresh an entity already in the identity map.
        $this->em->refresh($target);

        $actual = [$result->profile, $result->sourceLocale, $result->targetLocale];
        $expected = [$target->engine, (string) $target->source?->locale, (string) $target->targetLocale];
        if ($actual !== $expected) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Result %s is for %s, but the Target is %s.', $result->requestId, implode(' ', $actual), implode(' ', $expected)));
        }

        if (\in_array($target->getMarking(), WF::TRANSLATED_PLACES, true)) {
            $this->logger->info('duplicate result for {key}; already {marking}', ['key' => $target->key, 'marking' => $target->getMarking()]);

            return self::DUPLICATE;
        }

        if (!$result->isCompleted()) {
            $error = $result->error;
            $rejection = \sprintf('Worker failed %s: %s%s %s', $result->requestId, $error->code ?? 'unknown', ($error->retryable ?? false) ? ' (retryable)' : '', $error->message ?? '');

            return $this->untranslated($target);
        }

        $this->assertProvenance($target, $result);

        $profile = $this->catalog->worker($target->engine);
        $metadata = array_filter([
            'provider' => $profile['provider'],
            'model' => $result->provenance?->model,
            'revision' => $result->provenance?->revision,
            'profileVersion' => $profile['profileVersion'],
        ], static fn (mixed $v): bool => $v !== null);

        if (!$this->applier->apply($target, (string) $result->translatedText, null, $metadata)) {
            $rejection = 'Worker returned an empty translation for '.$result->requestId.'.';

            return $this->untranslated($target);
        }

        // Worker detail beyond the applier's profile allowlist; snake_case as on the wire.
        $p = $result->provenance;
        $target->provenance += array_filter(
            ['variant' => $p?->variant, 'runtime' => $p?->runtime, 'quantization' => $p?->quantization, 'beam_size' => $p?->beamSize],
            static fn (mixed $v): bool => $v !== null,
        );

        return self::APPLIED;
    }

    /** Back to `u`: no text, no callback, and the next push dispatches it again. */
    private function untranslated(Target $target): string
    {
        $target->setMarking(WF::PLACE_UNTRANSLATED);

        return self::FAILED;
    }

    /** A completed result must come from exactly the profile's pinned model. */
    private function assertProvenance(Target $target, TranslationResultMessage $result): void
    {
        $expected = $this->catalog->worker($target->engine);
        foreach (['model', 'revision', 'variant'] as $field) {
            $actual = $result->provenance?->{$field};
            if ($expected[$field] !== null && $actual !== $expected[$field]) {
                throw new UnrecoverableMessageHandlingException(\sprintf(
                    'Result %s %s "%s" does not match profile %s ("%s").',
                    $result->requestId, $field, $actual ?? 'missing', $target->engine, $expected[$field],
                ));
            }
        }
    }
}
