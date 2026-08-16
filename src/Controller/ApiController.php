<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\TranslationIntakeService;
use App\Service\TranslationPullService;
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
        private readonly LoggerInterface           $logger,
        private readonly TranslationIntakeService  $intake,
        private readonly TranslationPullService    $pull,
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

        $hashes = $this->pull->normalizeHashes($hashes);
        if ($hashes === []) {
            return new JsonResponse(['error' => 'No valid hashes.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Response shape is unchanged: a bare map, and [] rather than {} when nothing hits.
        // zm/bts/harvest parse this today. The RPC method reports `missing` as well; this
        // one cannot start doing so without breaking those callers.
        return new JsonResponse($this->pull->pullByHashes($hashes, $locale, $engine));
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
