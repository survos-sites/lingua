<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\TranslationSubscription;
use App\Message\FlushTranslationNotificationsMessage;
use App\Service\TranslationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Drains finished translations into webhooks, then decides whether to run again.
 *
 * Three outcomes, and the choice between them is what keeps this from becoming either a flood
 * or a permanent poll:
 *
 *   a page came back FULL      → re-dispatch immediately. More is ready this instant; waiting
 *                                would only add latency.
 *   nothing sent, but rows are
 *   still WAITING to translate → re-dispatch after a delay. The translator worker is mid-batch;
 *                                this is the case that would otherwise need a cron.
 *   nothing pending at all     → stop. The steady state is an empty queue, not a heartbeat.
 *
 * The attempt cap in {@see FlushTranslationNotificationsMessage} bounds the middle case.
 */
#[AsMessageHandler]
final class FlushTranslationNotificationsMessageHandler
{
    /** ~1 hour at RETRY_DELAY_MS. Past that, assume the translator is not coming back. */
    private const int MAX_ATTEMPTS = 120;

    private const int RETRY_DELAY_MS = 30_000;

    public function __construct(
        private readonly TranslationNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FlushTranslationNotificationsMessage $message): void
    {
        $result = $this->notifier->flushAll();

        if ($result['full']) {
            // Attempt is NOT incremented: the cap exists to bound waiting, and this branch is
            // making progress. Counting real work against the budget would cut a large backlog
            // off part-way through.
            $this->bus->dispatch(new FlushTranslationNotificationsMessage($message->attempt));

            return;
        }

        if ($this->awaitingTranslation() === 0) {
            if ($result['queued'] > 0) {
                $this->logger->info('translation flush complete: {count} translations in {queued} webhook(s)', [
                    'count' => $result['translations'],
                    'queued' => $result['queued'],
                ]);
            }

            return;
        }

        if ($message->attempt >= self::MAX_ATTEMPTS) {
            $this->logger->warning(
                'translation flush giving up after {attempts} attempts with subscriptions still untranslated. '
                . 'Run `bin/console lingua:webhook:flush` once the translator is healthy.',
                ['attempts' => $message->attempt],
            );

            return;
        }

        $this->bus->dispatch(
            new FlushTranslationNotificationsMessage($message->attempt + 1),
            [new DelayStamp(self::RETRY_DELAY_MS)],
        );
    }

    /**
     * Subscriptions whose target has not finished translating yet.
     *
     * A COUNT, not a fetch: this runs on every quiet pass and only ever asks "is there any
     * reason to come back?".
     */
    private function awaitingTranslation(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(TranslationSubscription::class, 's')
            ->join('s.target', 't')
            ->andWhere('s.notifiedAt IS NULL')
            ->andWhere('t.marking NOT IN (:done)')
            ->setParameter('done', \App\Workflow\TargetWorkflowInterface::TRANSLATED_PLACES)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
