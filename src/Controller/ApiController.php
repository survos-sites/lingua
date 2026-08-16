<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Target;
use App\Service\TranslationIntakeService;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class ApiController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface        $logger,
        private readonly TranslationIntakeService $intake,
    ) {}

    /**
     * Lingua "pull" endpoint for babel-style hash lookups.
     *
     * Client sends *source hashes*; server returns translations keyed by those source hashes:
     *   { "<sourceHash>": "<translatedText>", ... }
     */
    // Path is a literal on purpose. It is the deployed contract that zm/bts/harvest call, and
    // lingua runs on published vendor copies -- keying it off Survos\Lingua\Contracts\Http\LinguaApi
    // would let a stale vendor silently relocate a production endpoint.
    #[Route('/babel/pull', name: 'lingua_babel_pull', methods: ['POST', 'GET'])]
    public function pullBabel(
        Request $request,
        EntityManagerInterface $em,
        #[MapQueryParameter] ?string $locale = null,
        #[MapQueryParameter] ?string $engine = null,
    ): JsonResponse {
        try {
            $payload = $request->toArray();
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Invalid JSON body.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $hashes = $payload['hashes'] ?? [];
        if (!is_array($hashes) || $hashes === []) {
            return new JsonResponse(['error' => 'Missing hashes[].'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $hashes = array_values(array_unique(array_filter(array_map('strval', $hashes))));
        if ($hashes === []) {
            return new JsonResponse(['error' => 'No valid hashes.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // IMPORTANT: hashes are Source.hash, not Target.key.
        // We join t.source and filter by s.hash, then return map keyed by s.hash.
        $qb = $em->createQueryBuilder()
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

        $rows = $qb->getQuery()->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $h = (string) ($row['hash'] ?? '');
            if ($h === '') {
                continue;
            }
            $text = $row['text'];
            $map[$h] = is_string($text) ? $text : (string) $text;
        }

        return new JsonResponse($map);
    }


    /**
     * Main Lingua intake endpoint (batch translate).
     *
     * Returns:
     *   { "status": "ok", "response": { queued, items, missing, ... } }
     */
    #[Route('/batch-translate', name: 'api_queue_translation', methods: ['POST'])]
    public function batchRequest(
        #[MapRequestPayload] ?BatchRequest $payload = null,
    ): JsonResponse {
        if ($payload === null) {
            return $this->json(['status' => 'error', 'error' => 'Invalid or missing JSON body.'], 400);
        }

        $result = $this->intake->handle($payload);

        // Avoid noisy pretty-printed logs at warning level; keep one concise info line.
        $this->logger->info('Lingua batch handled', [
            'queued'  => $result['queued'] ?? null,
            'missing' => is_array($result['missing'] ?? null) ? count($result['missing']) : null,
            'items'   => is_array($result['items'] ?? null) ? count($result['items']) : null,
        ]);

        return $this->json(['status' => 'ok', 'response' => $result]);
    }
}
