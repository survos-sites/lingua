<?php

declare(strict_types=1);

namespace App\RPC\V1;

use App\RPC\V1\TranslateBatch\Request;
use App\RPC\V1\TranslateBatch\Response;
use App\Service\TranslationIntakeService;
use OV\JsonRPCAPIBundle\Core\Annotation\JsonRPCAPI;
use OV\JsonRPCAPIBundle\Core\ApiMethodInterface;
use OV\JsonRPCAPIBundle\Core\JRPCException;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\TranslatorBundle\Service\TranslatorManager;

/**
 * The write half of the lingua contract: texts in, translation jobs queued. Phase 2.
 *
 * Shares {@see TranslationIntakeService} with POST /batch-translate, so the two transports
 * cannot drift -- the same arrangement pullTranslations has with /babel/pull. The REST route
 * stays exactly as it is; zm/bts/harvest still call it.
 *
 *   curl -X POST https://lingua.wip/api/v1 \
 *     -H 'Content-Type: application/json' -H 'X-Api-Key: <key>' \
 *     -d '{"jsonrpc":"2.0","method":"translateBatch","params":{"source":"en",
 *          "target":["es","fr"],"texts":["good morning"]},"id":"1"}'
 *
 * Three things this fixes over the REST route:
 *
 *   - **Errors are errors.** The REST route answers a rejected payload with
 *     {"status":"ok","response":{"error":"Invalid payload: ..."}} at HTTP 200 -- a caller has
 *     to dig into the body to find out it failed. Here a bad payload is a real JSON-RPC error
 *     object with -32602 and a message naming the problem.
 *   - **An unknown engine is caught at the edge.** Nothing validated `engine` before: an
 *     engine that no TranslatorManager knows would create Target rows, queue them, and then
 *     fail one at a time inside the worker at TargetWorkflow's Assert::inArray -- after the
 *     write, asynchronously, where the caller never sees it. That is the "make it more
 *     reliable" half of this method.
 *   - **Batch.** Several (source, target-locale) groups travel in one request instead of one
 *     HTTP round trip per chunk per locale pair, which is what lingua:push does today. Worth
 *     ~160-200ms of pure overhead per avoided round trip -- see doc/BABEL-THROUGHPUT.md,
 *     which also explains why this is the leg where batching actually pays.
 *
 * Deliberately unchanged: this still only *queues* work. Translation happens in the worker, so
 * the write path is not synchronous and "faster" here never meant that.
 *
 * What DID change is how a caller learns the work finished. Send `callbackUrl` (plus `refs`,
 * your own key per text) and lingua POSTs a signed `translation.completed` webhook when they
 * are done, carrying the translations inline — instead of the caller running pullTranslations
 * on a timer and mostly finding nothing. Omit both and the polling contract is untouched.
 * See docs/webhooks.md.
 */
#[JsonRPCAPI(methodName: 'translateBatch', type: 'POST')]
final readonly class TranslateBatchMethod implements ApiMethodInterface
{
    public function __construct(
        private TranslationIntakeService $intake,
        private TranslatorManager $translators,
    ) {
    }

    /**
     * @throws JRPCException
     */
    public function call(Request $request): Response
    {
        $this->assertEngineIsKnown($request->getEngine());

        $result = $this->intake->handle(new BatchRequest(
            source: $request->getSource(),
            target: $request->getTarget(),
            texts: $request->getTexts(),
            engine: $request->getEngine(),
            insertNewStrings: $request->getInsertNewStrings(),
            forceDispatch: $request->getForceDispatch(),
            transport: $request->getTransport(),
            callbackUrl: $request->getCallbackUrl(),
            refs: $request->getRefs(),
        ));

        // The intake service reports a rejected payload as an `error` key rather than by
        // throwing, because the REST route hands that straight back in its envelope. Over RPC
        // it becomes a real error object -- the caller should not have to inspect a
        // successful result to learn that nothing happened.
        if (isset($result['error'])) {
            throw new JRPCException('Invalid params.', JRPCException::INVALID_PARAMS, (string) $result['error']);
        }

        return new Response(
            queued: (int) ($result['queued'] ?? 0),
            items: is_array($result['items'] ?? null) ? $result['items'] : [],
            missing: is_array($result['missing'] ?? null) ? array_values($result['missing']) : [],
        );
    }

    /**
     * @throws JRPCException when the engine is not one this lingua instance can run
     */
    private function assertEngineIsKnown(?string $engine): void
    {
        if ($engine === null || $engine === '') {
            return; // intake falls back to its own default
        }

        $known = $this->translators->names();
        if (in_array($engine, $known, true)) {
            return;
        }

        sort($known);

        throw new JRPCException(
            'Invalid params.',
            JRPCException::INVALID_PARAMS,
            sprintf('Unknown engine "%s". Configured engines: %s.', $engine, implode(', ', $known)),
        );
    }
}
