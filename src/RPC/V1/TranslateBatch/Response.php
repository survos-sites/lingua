<?php

declare(strict_types=1);

namespace App\RPC\V1\TranslateBatch;

/**
 * result for the `translateBatch` JSON-RPC method.
 *
 * Public promoted readonly, no accessors -- the response side has no CompilerPass
 * constraint, so it is written the conventional way.
 *
 * Note what is NOT here: `status`. The REST route answers
 * `{"status":"ok","response":{queued,items,missing}}` -- a double envelope the client has to
 * unwrap, and a status field that duplicates what HTTP already said. JSON-RPC states success
 * by returning `result` instead of `error`, so both layers are redundant and both are gone.
 * A failure is a real error object with a code now, not `status: "error"` at HTTP 200.
 */
final readonly class Response
{
    /**
     * @param int                        $queued  translation jobs dispatched
     * @param list<array<string, mixed>> $items   the Source rows, normalized (source.read)
     * @param list<string>               $missing texts lingua does not have and was not
     *                                            allowed to create (insertNewStrings: false)
     */
    public function __construct(
        public int $queued = 0,
        public array $items = [],
        public array $missing = [],
    ) {
    }
}
