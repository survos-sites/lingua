<?php

declare(strict_types=1);

namespace App\Service\TranslationWorker;

use App\Entity\TranslationJob;
use App\Message\TranslationWorker\TranslationResultMessage;
use App\Service\TargetTranslationApplier;
use App\Service\TranslationEngineCatalog;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Applies one worker result, idempotently.
 *
 * The job row is locked FOR UPDATE, so two deliveries of the same result (worker retry, broker
 * redelivery, outbox republish) serialize: the first applies, the second sees a terminal job
 * and only bumps duplicateResults.
 *
 * Writing the Target goes through {@see TargetTranslationApplier} — the same translated vs
 * identical decision as every other path. Its flush is what fires
 * TranslationCompletionNotifier, whose FlushTranslationNotificationsMessage goes to a Doctrine
 * transport inside this same transaction, so callbacks are scheduled iff the result commits.
 *
 * Results that disagree with the job they name are thrown as unrecoverable: they land in the
 * Doctrine `failed` transport for a human, and are never applied.
 */
final class TranslationResultProcessor
{
    public const string OUTCOME_TRANSLATED = 'translated';
    public const string OUTCOME_IDENTICAL = 'identical';
    public const string OUTCOME_FAILED = 'failed';
    public const string OUTCOME_STALE = 'stale';

    /** Worker provenance kept on the Target beyond the applier's profile allowlist. */
    private const array EXTRA_PROVENANCE = ['variant', 'runtime', 'quantization', 'beam_size'];

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
            $outcome = $this->apply($result);
            $this->em->flush();
            $connection->commit();

            return $outcome;
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->em->clear();
            throw $e;
        }
    }

    private function apply(TranslationResultMessage $result): string
    {
        $job = $this->em->find(TranslationJob::class, $result->requestId, LockMode::PESSIMISTIC_WRITE);
        if ($job === null) {
            throw new UnrecoverableMessageHandlingException('Unknown translation request_id '.$result->requestId.'.');
        }
        // FOR UPDATE does not refresh an entity already in the identity map.
        $this->em->refresh($job);

        if ([$result->profile, $result->sourceLocale, $result->targetLocale] !== [$job->profile, $job->sourceLocale, $job->targetLocale]) {
            throw new UnrecoverableMessageHandlingException(\sprintf(
                'Result %s identity %s %s->%s does not match job %s %s->%s.',
                $result->requestId, $result->profile, $result->sourceLocale, $result->targetLocale,
                $job->profile, $job->sourceLocale, $job->targetLocale,
            ));
        }

        if ($job->isTerminal || $job->outcome === self::OUTCOME_STALE) {
            $job->duplicateResults++;
            $this->logger->info('duplicate result for translation job {id} ({status})', ['id' => $job->requestId, 'status' => $job->status]);

            return 'duplicate';
        }

        $job->completedAt = new \DateTimeImmutable('now');
        $job->metrics = $result->metrics;
        $job->provenance = $this->provenance($result);

        // Superseded by a newer job, or the Target's Source no longer is what was sent:
        // record what came back, but never let it overwrite the newer outcome.
        if ($job->status === TranslationJob::STATUS_SUPERSEDED || $job->target->source?->hash !== $job->sourceHash) {
            $job->outcome = self::OUTCOME_STALE;
            $job->translatedText = $result->translatedText;
            $this->logger->info('stale result for translation job {id}; not applied', ['id' => $job->requestId]);

            return self::OUTCOME_STALE;
        }

        if (!$result->isCompleted()) {
            return $this->fail($job, ['code' => $result->error->code ?? 'unknown', 'message' => $result->error->message ?? '', 'retryable' => $result->error->retryable ?? false]);
        }

        $this->assertProvenance($job, $result);

        $profile = $this->catalog->worker($job->profile);
        $metadata = array_filter([
            'provider' => $profile['provider'],
            'model' => $result->provenance?->model,
            'revision' => $result->provenance?->revision,
            'profileVersion' => $profile['profileVersion'],
        ], static fn (mixed $v): bool => $v !== null);

        $job->translatedText = $result->translatedText;
        if (!$this->applier->apply($job->target, (string) $result->translatedText, null, $metadata)) {
            return $this->fail($job, ['code' => 'empty_translation', 'message' => 'Worker returned an empty translation.', 'retryable' => false]);
        }

        $job->target->provenance += array_filter(
            [...array_intersect_key($job->provenance, array_flip(self::EXTRA_PROVENANCE)), 'request_id' => $job->requestId],
            static fn (mixed $v): bool => $v !== null,
        );

        $job->status = TranslationJob::STATUS_COMPLETED;
        $job->outcome = $job->target->isIdentical ? self::OUTCOME_IDENTICAL : self::OUTCOME_TRANSLATED;

        return $job->outcome;
    }

    /** A completed result must come from exactly the profile's pinned model. */
    private function assertProvenance(TranslationJob $job, TranslationResultMessage $result): void
    {
        $expected = $this->catalog->worker($job->profile);
        foreach (['model', 'revision', 'variant'] as $field) {
            $actual = $result->provenance?->{$field};
            if ($expected[$field] !== null && $actual !== $expected[$field]) {
                throw new UnrecoverableMessageHandlingException(\sprintf(
                    'Result %s %s "%s" does not match profile %s ("%s").',
                    $job->requestId, $field, $actual ?? 'missing', $job->profile, $expected[$field],
                ));
            }
        }
    }

    /** @return array<string, string|int|null> snake_case, as on the wire */
    private function provenance(TranslationResultMessage $result): array
    {
        $p = $result->provenance;

        return $p === null ? [] : ['model' => $p->model, 'revision' => $p->revision, 'variant' => $p->variant, 'runtime' => $p->runtime, 'quantization' => $p->quantization, 'beam_size' => $p->beamSize];
    }

    /** @param array{code?:string, message?:string, retryable?:bool} $error */
    private function fail(TranslationJob $job, array $error): string
    {
        // The Target stays untranslated: no text, no marking change, so no callback fires and
        // a later push may dispatch it again.
        $job->status = TranslationJob::STATUS_FAILED;
        $job->outcome = self::OUTCOME_FAILED;
        $job->error = $error;
        $this->logger->warning('translation job {id} failed: {code}', ['id' => $job->requestId, 'code' => $error['code'] ?? '?']);

        return self::OUTCOME_FAILED;
    }
}
