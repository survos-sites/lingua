<?php

namespace App\Repository;

use App\Entity\Source;
use App\Entity\Target;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Repository\QueryBuilderHelperInterface;
use Survos\FieldBundle\Repository\QueryBuilderHelperTrait;

/**
 * @extends ServiceEntityRepository<Target>
 */
class TargetRepository extends ServiceEntityRepository implements QueryBuilderHelperInterface
{
    use QueryBuilderHelperTrait;
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Target::class);
    }

    /**
     * @param Source[] $sources
     * @param string[] $locales
     * @return Target[]
     */
    final public function findExistingForSourcesAndLocales(array $sources, array $locales, string $engine): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.source IN (:sources)')
            ->andWhere('t.targetLocale IN (:locales)')
            ->andWhere('t.engine = :engine')
            ->setParameter('sources', $sources)
            ->setParameter('locales', $locales)
            ->setParameter('engine', $engine)
            ->getQuery()
            ->getResult();
    }

}
