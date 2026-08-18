<?php

declare(strict_types=1);

namespace App\Message;

/**
 * "Translate these Targets — they all share one language pair."
 *
 * ## Why a message per BATCH and not per Target
 *
 * LibreTranslate is CTranslate2 underneath, and its `/translate` accepts `q` as an ARRAY.
 * For a three-word place name almost all of a request's ~1s is fixed overhead — HTTP,
 * tokenizer setup, model dispatch — not inference, so 50 strings in one call is typically
 * 5-20x the throughput of 50 calls. The server was never the bottleneck: `LT_BATCH_LIMIT`,
 * `LT_CHAR_LIMIT` and `LT_REQ_LIMIT` are all `-1` (unlimited) on babel.survos.com. We were.
 *
 * ## Why not Symfony's BatchHandlerInterface
 *
 * That is the obvious answer — buffer messages in the handler, flush at N or on idle — and it
 * does not work here. `WorkflowHelperService::handleTransition` is registered with a bare
 * `#[AsMessageHandler]`, so it handles `TransitionMessage` from EVERY transport. A second
 * handler scoped with `fromTransport: 'target.translate'` would not replace it; Messenger
 * would call BOTH, translating every Target twice. Scoping state-bundle's handler instead
 * would change behaviour for every workflow in every app.
 *
 * (harvest's `BatchResizeRequests` was an earlier run at BatchHandlerInterface and hit the
 * neighbouring version of the same problem — one message class shared by every transition, so
 * the buffer filled with messages for other queues. It was left disabled, and has now been
 * deleted along with the resize workflow it served.)
 *
 * So the batch is formed at DISPATCH time instead, which is strictly better here anyway:
 * TranslationIntakeService already knows the whole request grouped by locale, so grouping is
 * exact rather than "whatever happened to arrive together".
 *
 * ## Grouping
 *
 * One message = one `(sourceLocale, targetLocale, engine)` tuple, because that is exactly what
 * one `/translate` call takes. Target keys rather than texts: the text lives on Source, the
 * handler needs the Target rows anyway to write markings, and a key list survives a deploy
 * where a serialized entity would not.
 */
final class TranslateBatchMessage
{
    /**
     * @param list<string> $targetKeys Target::$key values, all sharing the tuple below
     */
    public function __construct(
        public readonly string $sourceLocale,
        public readonly string $targetLocale,
        public readonly string $engine,
        public readonly array $targetKeys,
    ) {
    }
}
