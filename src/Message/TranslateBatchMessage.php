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
 *
 * ## English hub
 *
 * For a non-English source, `$pivotLocale` and `$thenLocales` turn N independent translations
 * into a hub leg plus N-1 spokes: `2N-1` inference passes become `N`. See those properties.
 * For an English source there is no hub leg — `en` already IS the hub — so both stay empty and
 * nothing changes.
 */
final class TranslateBatchMessage
{
    /**
     * The hub language. Every non-English translation goes through it.
     *
     * Not arbitrary: LibreTranslate is Argos underneath, which ships X↔en models and pivots
     * through English internally for every other pair. `da→hu` is therefore already two
     * inference passes — we just never see the English half, cannot store it, and pay for it
     * again for every additional target language.
     */
    public const string HUB_LOCALE = 'en';

    /**
     * @param list<string> $targetKeys Target::$key values, all sharing the tuple below
     * @param list<string> $thenLocales locales to fan out to once this batch lands
     */
    public function __construct(
        public readonly string $sourceLocale,
        public readonly string $targetLocale,
        public readonly string $engine,
        public readonly array $targetKeys,
        /**
         * Read the input text from `Target(source, pivotLocale)` instead of from `Source`.
         *
         * This is the spoke leg. Set to HUB_LOCALE when translating `en→hu` on behalf of a
         * Danish source: the row being written is still `Target(da-source, hu)` — that is what
         * the client asked for and looks up — but its INPUT is the already-stored Danish→English
         * translation. One pass instead of two, and for `da` and `es` the English side is
         * already 100% translated, so those spokes cost nothing extra at all.
         *
         * Null = translate directly from the source text.
         */
        public readonly ?string $pivotLocale = null,
        /**
         * Locales to dispatch as spoke batches once THIS batch has been applied.
         *
         * This is the hub leg's continuation. Spokes cannot run until the hub text exists, and
         * chaining from the handler is the simplest correct ordering — no scheduler, no
         * polling for readiness.
         *
         * Bounded on purpose: a message that dispatches more messages is exactly the shape
         * that flooded mediary's queue, so the handler caps and logs the fan-out rather than
         * trusting the caller.
         */
        public readonly array $thenLocales = [],
    ) {
    }

    /** True when this batch produces the hub text that other locales will be built from. */
    public function isHub(): bool
    {
        return $this->targetLocale === self::HUB_LOCALE && $this->thenLocales !== [];
    }
}
