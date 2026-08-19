<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Source;
use App\Entity\Target;
use App\Repository\TargetRepository;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Machine-readable translation progress, for pinging rather than eyeballing.
 *
 * The charts at / and /admin/charts already compute this, but only for a human who remembers to
 * refresh. The counterpart on mediary is GET /status.json and this deliberately mirrors its shape
 * so one monitor can poll both.
 *
 * What actually answers "is translation moving": pending vs done per locale PAIR. A single global
 * total hides the case that matters — a pair with thousands pending and nothing completing, next
 * to pairs that finished long ago.
 *
 * `done` is t + i, never t alone. PLACE_IDENTICAL means the engine returned text identical to the
 * source (common for proper nouns, dates, codes) — it is a completed translation, not a pending
 * one. TargetWorkflowInterface::TRANSLATED_PLACES exists precisely because callers kept getting
 * this wrong, and its docblock says so; reporting only `t` would under-report completion and make
 * a finished pair look stuck.
 *
 * Counts only — no source text, no translated text, nothing that could carry archive content.
 */
final class StatusController extends AbstractController
{
    public function __construct(
        private readonly TargetRepository $targetRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/status.json', name: 'app_status_json', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        // Same grouping the charts page uses. NOT filtered to the configured locale list: an
        // unexpected locale pair showing up is exactly the kind of thing this endpoint exists to
        // reveal, and the charts' filter would silently drop it.
        $rows = $this->targetRepository->createQueryBuilder('t')
            ->join('t.source', 's')
            ->groupBy('t.targetLocale', 't.marking', 's.locale')
            ->select('t.targetLocale AS targetLocale, t.marking AS marking, s.locale AS sourceLocale, COUNT(t) AS count')
            ->getQuery()
            ->getArrayResult();

        $pairs = [];
        $totals = ['pending' => 0, 'translated' => 0, 'identical' => 0, 'done' => 0, 'total' => 0];

        foreach ($rows as $row) {
            $pair = sprintf('%s->%s', $row['sourceLocale'] ?? '?', $row['targetLocale'] ?? '?');
            $count = (int) $row['count'];
            $marking = $row['marking'];

            $pairs[$pair] ??= ['pending' => 0, 'translated' => 0, 'identical' => 0, 'done' => 0, 'total' => 0];

            $bucket = match ($marking) {
                TargetWorkflowInterface::PLACE_UNTRANSLATED => 'pending',
                TargetWorkflowInterface::PLACE_TRANSLATED => 'translated',
                TargetWorkflowInterface::PLACE_IDENTICAL => 'identical',
                default => null,
            };

            // An unknown marking is a data or migration bug. Surface it under its raw key rather
            // than dropping it, so the pair totals still add up and the oddity is visible.
            $key = $bucket ?? sprintf('unknown:%s', (string) $marking);
            $pairs[$pair][$key] = ($pairs[$pair][$key] ?? 0) + $count;
            $pairs[$pair]['total'] += $count;
            $totals[$key] = ($totals[$key] ?? 0) + $count;
            $totals['total'] += $count;

            if (in_array($marking, TargetWorkflowInterface::TRANSLATED_PLACES, true)) {
                $pairs[$pair]['done'] += $count;
                $totals['done'] += $count;
            }
        }

        // Busiest first: with hundreds of pairs, the ones with outstanding work are the ones a
        // human scrolling this actually wants at the top.
        uasort($pairs, static fn (array $a, array $b): int => $b['pending'] <=> $a['pending']);

        return new JsonResponse([
            'app' => 'lingua',
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'entities' => [
                'sources' => $this->entityManager->getRepository(Source::class)->count([]),
                'targets' => $this->entityManager->getRepository(Target::class)->count([]),
            ],
            'totals' => $totals,
            // The single number to alert on. Non-zero and not falling = translation is not moving.
            'pending' => $totals['pending'],
            'pairs' => $pairs,
        ]);
    }
}
