<?php

declare(strict_types=1);

namespace App\Service\TranslationWorker;

use App\Entity\Target;
use App\Entity\TranslationJob;
use App\Message\TranslationWorker\PublishTranslationJobMessage;
use App\Repository\TranslationJobRepository;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cache-miss path for worker profiles: one TranslationJob per Target, durably queued.
 *
 * Translation memory is the Target itself — (source, targetLocale, engine) with engine = the
 * profile name — so a Target already in a translated place is a hit and nothing is sent.
 * Job rows and their outbox messages are written in ONE transaction: `translation_outbox` is
 * a Doctrine transport on the same connection, so a crash can never leave a job that will
 * not be published, nor a publish for a job that was rolled back.
 *
 * No English-hub pivot: each job is a direct (source text → target locale) request. Which
 * pairs exist is the worker profile's `languagePairs`, validated at intake.
 */
final class TranslationJobDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslationJobRepository $jobs,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Target[] $targets all share $profile as their engine
     *
     * @return array{queued:int, hits:int, inFlight:int, superseded:int}
     */
    public function dispatch(array $targets, string $profile, bool $force = false): array
    {
        $stats = ['queued' => 0, 'hits' => 0, 'inFlight' => 0, 'superseded' => 0];

        $this->em->wrapInTransaction(function () use ($targets, $profile, $force, &$stats): void {
            $active = $this->jobs->activeByTargetKey($targets);
            $requestIds = [];

            foreach ($targets as $target) {
                if ($target->engine !== $profile) {
                    throw new \LogicException(\sprintf('Target %s belongs to engine %s, not %s.', $target->key, $target->engine, $profile));
                }

                if (!$force && \in_array($target->getMarking(), TargetWorkflowInterface::TRANSLATED_PLACES, true)) {
                    $stats['hits']++;
                    continue;
                }

                $previous = $active[(string) $target->key] ?? [];
                if ($previous !== [] && !$force) {
                    // Already on its way. Re-pushing must not multiply worker load; use
                    // forceDispatch to replace a job believed lost.
                    $stats['inFlight']++;
                    continue;
                }
                foreach ($previous as $job) {
                    $job->status = TranslationJob::STATUS_SUPERSEDED;
                    $stats['superseded']++;
                }

                $source = $target->source ?? throw new \LogicException('Target '.$target->key.' has no source.');
                $job = new TranslationJob(
                    requestId: Uuid::v7()->toRfc4122(),
                    target: $target,
                    profile: $profile,
                    sourceLocale: (string) $source->locale,
                    targetLocale: (string) $target->targetLocale,
                    inputText: (string) $source->getText(),
                    sourceHash: (string) $source->hash,
                );
                $this->em->persist($job);
                $requestIds[] = $job->requestId;
            }

            $this->em->flush();

            foreach ($requestIds as $requestId) {
                $this->bus->dispatch(new PublishTranslationJobMessage($requestId));
            }
            $stats['queued'] = \count($requestIds);
        });

        $this->logger->info('worker jobs for {profile}: {queued} queued, {hits} memory hits, {inFlight} in flight, {superseded} superseded', ['profile' => $profile, ...$stats]);

        return $stats;
    }
}
