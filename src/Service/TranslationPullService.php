<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Target;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The read half of the lingua contract: source hashes in, translations out.
 *
 * Extracted from ApiController::pullBabel() so the REST route (POST /babel/pull) and the
 * JSON-RPC method (pullTranslations) cannot drift -- the same pattern mediary uses with
 * AssetProbeService behind both /fetch/media/by-ids and probeAssets.
 */
final readonly class TranslationPullService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Normalise a caller-supplied hash list: strings, trimmed of blanks, deduped.
     *
     * @param  array<mixed> $hashes
     * @return list<string>
     */
    public function normalizeHashes(array $hashes): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $hashes))));
    }

    /**
     * @param  list<string> $hashes source hashes (Source.hash, NOT Target.key)
     * @return array<string,string> map[sourceHash => translatedText], only for hashes that
     *                              have a translation matching the filters
     */
    public function pullByHashes(array $hashes, ?string $locale = null, ?string $engine = null): array
    {
        if ($hashes === []) {
            return [];
        }

        // IMPORTANT: hashes are Source.hash, not Target.key.
        // Join t.source and filter by s.hash, then return a map keyed by s.hash.
        $qb = $this->em->createQueryBuilder()
            ->select('s.hash AS hash, t.targetText AS text')
            ->from(Target::class, 't')
            ->join('t.source', 's')
            ->andWhere('s.hash IN (:hashes)')
            ->andWhere('t.marking IN (:markings)')
            ->setParameter('hashes', $hashes)
            ->setParameter('markings', [TargetWorkflowInterface::PLACE_TRANSLATED]);

        if ($locale) {
            $qb->andWhere('t.targetLocale = :locale')
                ->setParameter('locale', $locale);
        }
        if ($engine) {
            $qb->andWhere('t.engine = :engine')
                ->setParameter('engine', $engine);
        }

        $map = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $h = (string) ($row['hash'] ?? '');
            if ($h === '') {
                continue;
            }
            $text = $row['text'];
            $map[$h] = is_string($text) ? $text : (string) $text;
        }

        return $map;
    }

    /**
     * Hashes the caller asked for that came back with nothing.
     *
     * The REST endpoint returns only the map, so a caller cannot tell "lingua has never seen
     * this string" from "lingua knows it but has not translated it yet" -- both are simply
     * absent. The RPC response reports this explicitly.
     *
     * @param  list<string>         $requested
     * @param  array<string,string> $found
     * @return list<string>
     */
    public function missing(array $requested, array $found): array
    {
        return array_values(array_diff($requested, array_keys($found)));
    }
}
