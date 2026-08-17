<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TranslationSubscription;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TranslationSubscription>
 */
class TranslationSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TranslationSubscription::class);
    }

    /**
     * Callback URLs with at least one translated-but-unannounced subscription.
     *
     * Drives the flush: one webhook is built per URL, so this is the list of webhooks that
     * currently have something to say. DISTINCT rather than fetching the rows themselves —
     * the caller pages through each URL's rows separately with a limit, and loading every
     * pending row across every subscriber first would defeat that.
     *
     * @return list<string>
     */
    public function pendingCallbackUrls(int $limit = 50): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.callbackUrl AS url')
            ->join('s.target', 't')
            ->andWhere('s.notifiedAt IS NULL')
            ->andWhere('t.marking IN (:done)')
            ->setParameter('done', TargetWorkflowInterface::TRANSLATED_PLACES)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r): string => (string) $r['url'], $rows);
    }

    /**
     * Translated-but-unannounced subscriptions for one subscriber.
     *
     * Ordered by id so a flush that is interrupted resumes where it left off rather than
     * re-reading the same page — notifiedAt is only written after the message is queued, so
     * an interrupted run leaves the page pending, not lost.
     *
     * @return TranslationSubscription[]
     */
    public function pendingFor(string $callbackUrl, int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.target', 't')
            ->addSelect('t')
            ->join('t.source', 'src')
            ->addSelect('src')
            ->andWhere('s.callbackUrl = :url')
            ->andWhere('s.notifiedAt IS NULL')
            ->andWhere('t.marking IN (:done)')
            ->setParameter('url', $callbackUrl)
            ->setParameter('done', TargetWorkflowInterface::TRANSLATED_PLACES)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
