<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Target;
use App\Entity\TranslationJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TranslationJob>
 */
class TranslationJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TranslationJob::class);
    }

    /**
     * @param Target[] $targets
     *
     * @return array<string, list<TranslationJob>> target key => active jobs
     */
    public function activeByTargetKey(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        /** @var TranslationJob[] $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->andWhere('j.target IN (:targets)')
            ->andWhere('j.status IN (:active)')
            ->setParameter('targets', $targets)
            ->setParameter('active', TranslationJob::ACTIVE)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($jobs as $job) {
            $out[(string) $job->target->key][] = $job;
        }

        return $out;
    }
}
