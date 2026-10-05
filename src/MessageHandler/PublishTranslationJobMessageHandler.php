<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\TranslationJob;
use App\Message\TranslationWorker\PublishTranslationJobMessage;
use App\Message\TranslationWorker\TranslationJobRequest;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Drains the Doctrine outbox onto the worker broker.
 *
 * At-least-once: a crash between the broker confirm and the flush below republishes the same
 * request_id, which the result handler treats as a duplicate. Only PENDING jobs publish, so a
 * job superseded or already answered before its outbox row was consumed is skipped.
 */
#[AsMessageHandler]
final class PublishTranslationJobMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishTranslationJobMessage $message): void
    {
        $job = $this->em->find(TranslationJob::class, $message->requestId);
        if ($job === null || $job->status !== TranslationJob::STATUS_PENDING) {
            $this->logger->info('translation job {id} not pending ({status}); not publishing', ['id' => $message->requestId, 'status' => $job === null ? 'missing' : $job->status]);

            return;
        }

        // Routed to the send-only `translation_jobs` transport, which publishes synchronously
        // (mandatory + confirms); a failure throws here and Messenger retries this outbox row.
        $this->bus->dispatch(new TranslationJobRequest(
            requestId: $job->requestId,
            text: $job->inputText,
            sourceLocale: $job->sourceLocale,
            targetLocale: $job->targetLocale,
            profile: $job->profile,
        ));

        // Conditional, not a flush of the loaded entity: a fast worker's result may already have
        // completed this job in another process, and that outcome must not be overwritten.
        $this->em->createQuery('UPDATE '.TranslationJob::class.' j SET j.status = :dispatched, j.dispatchedAt = :now WHERE j.requestId = :id AND j.status = :pending')
            ->setParameters(['dispatched' => TranslationJob::STATUS_DISPATCHED, 'now' => new \DateTimeImmutable('now'), 'id' => $job->requestId, 'pending' => TranslationJob::STATUS_PENDING])
            ->execute();
    }
}
