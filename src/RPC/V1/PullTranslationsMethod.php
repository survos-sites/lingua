<?php

declare(strict_types=1);

namespace App\RPC\V1;

use App\RPC\V1\PullTranslations\Request;
use App\RPC\V1\PullTranslations\Response;
use App\Service\TranslationPullService;
use OV\JsonRPCAPIBundle\Core\Annotation\JsonRPCAPI;
use OV\JsonRPCAPIBundle\Core\ApiMethodInterface;

/**
 * The read half of the lingua contract, over JSON-RPC. Phase 1 of doc/JSONRPC.md.
 *
 * Chosen first for the same reason mediary chose probeAssets: it is read-only, so getting it
 * wrong costs nothing, and it exercises the whole bundle on real traffic before the write
 * path (translateBatch) -- which cannot ship until authentication does -- goes anywhere near
 * it. Both transports call {@see TranslationPullService} so they cannot drift.
 *
 *   curl -X POST https://lingua.wip/api/v1 \
 *     -H 'Content-Type: application/json' \
 *     -d '{"jsonrpc":"2.0","method":"pullTranslations","params":{"hashes":["e69eb61789730bd1"]},"id":"1"}'
 *
 * What this buys over POST /babel/pull:
 *   - params arrive deserialised and type-checked, instead of toArray() plus three
 *     hand-written 400s;
 *   - `missing` comes back, so a caller can tell "lingua has never seen this" from
 *     "lingua has it but has not translated it yet" -- indistinguishable today;
 *   - a JSON-RPC *batch* is handled by the bundle, so one round trip can carry several
 *     locale groups. That matters: measured against babel, an app<->lingua round trip is
 *     ~160-200ms of pure overhead (see doc/BABEL-THROUGHPUT.md), and serial per-chunk
 *     chatter is what once made querying lingua's database directly look attractive.
 *
 * Note the bundle cannot stream: responses are fully buffered, and streaming JSON-RPC is an
 * MCP extension rather than part of JSON-RPC 2.0. Batch is the round-trip fix here.
 */
#[JsonRPCAPI(methodName: 'pullTranslations', type: 'POST')]
final readonly class PullTranslationsMethod implements ApiMethodInterface
{
    public function __construct(private TranslationPullService $pull)
    {
    }

    public function call(Request $request): Response
    {
        $hashes = $this->pull->normalizeHashes($request->getHashes());

        $translations = $this->pull->pullByHashes($hashes, $request->getLocale(), $request->getEngine());

        return new Response(
            translations: $translations,
            missing: $this->pull->missing($hashes, $translations),
        );
    }
}
