<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Source;
use App\Entity\Target;
use App\Entity\TranslationSubscription;
use App\Message\FlushTranslationNotificationsMessage;
use App\Message\TranslateBatchMessage;
use App\Repository\SourceRepository;
use App\Repository\TargetRepository;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\Lingua\Core\Identity\HashUtil;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class TranslationIntakeService
{
    /**
     * Characters per TranslateBatchMessage — the PRIMARY bound.
     *
     * Translation cost scales with text volume, not with array length, so a count alone cannot
     * bound the work: 100 vocabulary terms ("Papír", "egyéni") and 100 museum descriptions differ
     * by orders of magnitude while both being "100". Sizing by count is why this has now tripped
     * the same idle timeout twice — first at 200 on mus/saveoursigns, then again at 100 on
     * musdig, where a 100-key batch died at exactly 60s and burned three redeliveries before
     * landing in the failure transport.
     *
     * Measured on fsn1 2026-08-19 against libretranslate.survos.com, unique texts, while the
     * engine was already at ~490% CPU: 100 short strings (~1.7k chars) took 11.8s — roughly
     * 0.007 s/char under contention. Against the 60s idle timeout that puts the ceiling near
     * 8.5k chars, so 5k leaves meaningful headroom for longer strings and a busier engine while
     * still collapsing nearly all per-request overhead.
     *
     * Note the engine itself is not the constraint: babel.survos.com runs LT_BATCH_LIMIT /
     * LT_CHAR_LIMIT / LT_REQ_LIMIT all at -1 (unlimited). What bites is the HTTP client's idle
     * timeout, and redelivery cost — a failed batch re-translates the whole chunk, so a smaller
     * chunk is also a cheaper retry.
     */
    private const int TRANSLATE_CHUNK_CHARS = 5_000;

    /**
     * Targets per TranslateBatchMessage — a SECONDARY hard cap.
     *
     * Chars alone would let ten thousand one-word terms into a single message; this bounds the
     * array size, the serialized envelope, and the blast radius of one redelivery regardless of
     * how short the texts are.
     */
    private const int TRANSLATE_CHUNK = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SourceRepository       $sourceRepository,
        private readonly TargetRepository       $targetRepository,
        private readonly MessageBusInterface    $bus,
        private readonly NormalizerInterface    $normalizer,
        private readonly LoggerInterface        $logger,
        private readonly AsyncQueueLocator      $asyncQueueLocator,
    ) {}

    /**
     * Process incoming texts:
     * - ensure Source rows exist (if allowed)
     * - ensure Target rows exist for (source, targetLocale, engine)
     * - queue translations for new + eligible existing Targets
     *
     * @return array{
     *   queued:int,
     *   items:array<mixed>,
     *   missing:list<string>,
     *   error?:string
     * }
     */
    public function handle(BatchRequest $payload): array
    {
        $fromRaw   = trim((string) $payload->source);
        $engineRaw = trim((string) ($payload->engine ?? 'libre'));

        $toLocalesRaw = array_values(array_filter(array_map(
            static fn($v) => trim((string) $v),
            (array) $payload->target
        )));

        $rawTexts = array_values(array_filter(array_map(
            static fn($v) => trim((string) $v),
            (array) $payload->texts
        )));

        // Caller's own key per text, joined on the TRIMMED TEXT rather than on position.
        // $rawTexts above is filtered and reindexed, so index i of it is not index i of
        // $payload->texts the moment any text is blank — and text is the right join key
        // anyway, since it is what the hash and the dedupe below are already built on.
        $refByText = [];
        foreach ((array) $payload->texts as $i => $text) {
            $text = trim((string) $text);
            $ref = trim((string) (($payload->refs[$i] ?? null) ?? ''));
            if ($text !== '' && $ref !== '') {
                $refByText[$text] ??= $ref;
            }
        }

        if ($fromRaw === '' || $toLocalesRaw === [] || $rawTexts === []) {
            return [
                'queued'  => 0,
                'items'   => [],
                'missing' => $rawTexts,
                'error'   => 'Invalid payload: source/target/texts required.',
            ];
        }

        // Normalize inputs once
        $from      = HashUtil::normalizeLocale($fromRaw);
        $engine    = HashUtil::normalizeEngine($engineRaw);
        $toLocales = array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $toLocalesRaw)));

        // Remove degenerate targets
        $toLocales = array_values(array_filter($toLocales, static fn(string $l) => $l !== '' && $l !== $from));

        if ($toLocales === []) {
            return [
                'queued'  => 0,
                'items'   => [],
                'missing' => [],
                'error'   => 'No target locales after normalization (or only equals source).',
            ];
        }

        $insertNewStrings = (bool) $payload->insertNewStrings;
        $forceDispatch    = (bool) $payload->forceDispatch;

        $this->logger->info('Lingua intake: start', [
            'source'           => $from,
            'targets'          => $toLocales,
            'engine'           => $engine,
            'texts_in'         => \count($rawTexts),
            'insertNewStrings' => $insertNewStrings,
            'forceDispatch'    => $forceDispatch,
            'transport'        => $payload->transport ?? null,
        ]);

        // 1) Normalize/dedupe texts -> source hashes
        $byHash = []; // hash => original text
        $skipped = 0;

        foreach ($rawTexts as $s) {
            if ($s === '') { continue; }

            // Optional rule: skip pure numbers (keep if you truly want this)
            if (\preg_match('/^\d+$/', $s)) {
                $skipped++;
                continue;
            }

            $h = HashUtil::calcSourceKey($s, $from);
            $byHash[$h] ??= $s;
        }

        // array_keys() here can silently coerce a purely-numeric hash string (e.g. all-digit
        // xxh3 output, rare but real at a few thousand hashes) into a PHP int array key -- cast
        // back to string explicitly so every downstream consumer (Source::$hash, etc.) is safe.
        $hashes = array_map('strval', array_keys($byHash));
        if ($hashes === []) {
            $this->logger->info('Lingua intake: nothing to do after normalization', ['skipped' => $skipped]);
            return ['queued' => 0, 'items' => [], 'missing' => []];
        }

        // 2) Fetch existing Sources by hash
        /** @var Source[] $existingSources */
        $existingSources = $this->sourceRepository->findBy(['hash' => $hashes]);

        $sourceByHash = [];
        foreach ($existingSources as $source) {
            $sourceByHash[(string) $source->hash] = $source;
        }

        // 3) Create missing Sources if allowed
        $missingHashes = array_values(array_diff($hashes, array_keys($sourceByHash)));
        $createdSources = 0;

        if ($missingHashes !== [] && $insertNewStrings) {
            foreach ($missingHashes as $h) {
                $text = $byHash[$h];

                $source = new Source(
                    text: $text,
                    locale: $from,
                    hash: $h
                );

                if ($source->hash !== $h) {
                    $this->logger->warning('Lingua intake: source hash mismatch (should not happen)', [
                        'expected' => $h,
                        'actual'   => $source->hash,
                        'source'   => $from,
                    ]);
                }

                $this->em->persist($source);
                $sourceByHash[$h] = $source;
                $createdSources++;
            }

            $this->em->flush();
        }

        // If we are not allowed to insert new strings, report missing texts back to caller.
        $missingOut = $insertNewStrings
            ? []
            : array_values(array_map(static fn(string $h) => $byHash[$h], $missingHashes));

        $sources = array_values($sourceByHash);
        if ($sources === []) {
            $this->logger->warning('Lingua intake: no sources found/created', [
                'hashes' => \count($hashes),
                'missingHashes' => \count($missingHashes),
            ]);

            return [
                'queued'  => 0,
                'items'   => [],
                'missing' => $missingOut,
            ];
        }

        // 4) Fetch existing Targets by tuple (source, locale, engine)
        /** @var Target[] $existingTargets */
        $existingTargets = $this->targetRepository->findExistingForSourcesAndLocales($sources, $toLocales, $engine);

        $targetByTuple = []; // "sourceId|locale|engine" => Target
        foreach ($existingTargets as $t) {
            $tuple = $t->source->getId().'|'.$t->targetLocale.'|'.$t->engine;
            $targetByTuple[$tuple] = $t;
        }

        // 5) Create missing targets + decide dispatch list
        //
        // Keyed by TARGET LOCALE, not flat, because that is the unit one /translate call
        // takes: `$from` and `$engine` are uniform across the whole request, so the locale is
        // the only thing that splits a batch. Grouping here rather than hoping consecutive
        // messages happen to match is the whole advantage of batching at dispatch time —
        // see App\Message\TranslateBatchMessage.
        /** @var array<string, list<string>> $toDispatch targetLocale => Target::$key[] */
        $toDispatch = [];
        $createdTargets = 0;
        $eligibleExisting = 0;

        // Which Sources still need their English built, and which target key belongs to which
        // Source. Both are needed at dispatch time to tell a spoke whose hub is ALREADY
        // translated (dispatch it now, pivoting) from one that must wait for the hub leg.
        /** @var array<int|string,true> $hubPendingSourceIds */
        $hubPendingSourceIds = [];
        /** @var array<string,int|string> $sourceIdByTargetKey */
        $sourceIdByTargetKey = [];
        // Text length per target key, captured here because $source is in scope and the chunker
        // downstream only ever sees keys. Measuring at dispatch avoids a second pass over the
        // sources purely to ask "how big is this really?".
        /** @var array<string,int> $charsByTargetKey */
        $charsByTargetKey = [];

        foreach ($toLocales as $loc) {
            foreach ($sources as $source) {
                $tuple = $source->getId().'|'.$loc.'|'.$engine;

                $t = $targetByTuple[$tuple] ?? null;
                if (!$t) {
                    $t = new Target($source, $loc, $engine);
                    $this->em->persist($t);
                    $targetByTuple[$tuple] = $t;

                    $toDispatch[$loc][] = $t->key;
                    $sourceIdByTargetKey[(string) $t->key] = $source->getId();
                    $charsByTargetKey[(string) $t->key] = mb_strlen((string) $source->getText());
                    if ($loc === TranslateBatchMessage::HUB_LOCALE) {
                        $hubPendingSourceIds[$source->getId()] = true;
                    }
                    $createdTargets++;
                    continue;
                }

                if ($forceDispatch || $t->getMarking() !== TargetWorkflowInterface::PLACE_TRANSLATED) {
                    $toDispatch[$loc][] = $t->key;
                    $sourceIdByTargetKey[(string) $t->key] = $source->getId();
                    $charsByTargetKey[(string) $t->key] = mb_strlen((string) $source->getText());
                    if ($loc === TranslateBatchMessage::HUB_LOCALE) {
                        $hubPendingSourceIds[$source->getId()] = true;
                    }
                    $eligibleExisting++;
                }
            }
        }

        $this->em->flush();

        // 5b) Record who wants to be told. Only when the caller asked to be — without a
        // callbackUrl this whole step is skipped and the batch behaves exactly as it always
        // has (queue now, poll with lingua:pull later).
        $subscribed = $this->subscribe($payload->callbackUrl, $targetByTuple, $byHash, $refByText);

        // 6) Dispatch messages (new + eligible existing)
        $stamps = [];
        if ($payload->transport) {
            $stamps[] = new TransportNamesStamp($payload->transport);
        }

        // TRANSITION_TRANSLATE is declared `async: true` (TargetWorkflowInterface), so
        // AsyncQueueLocator::stamps() ALWAYS attaches its own async-routed transport stamp for
        // that transition — which silently overrode the $stamps built above from
        // $payload->transport, so a caller requesting transport=sync never actually got
        // synchronous processing. That only applies to TransitionMessage; TranslateBatchMessage
        // is routed by messenger.yaml and honours $stamps directly. The flag is still set for
        // any TransitionMessage dispatched elsewhere in this request.
        if ($payload->transport === 'sync') {
            $this->asyncQueueLocator->sync = true;
        }

        // ONE message per (locale, chunk), NOT one per Target. A push of 5,000 strings into
        // three locales used to be 15,000 messages and 15,000 HTTP calls to LibreTranslate;
        // it is now ~75 messages and ~75 calls, each carrying up to CHUNK strings in `q`.
        //
        // `queued` deliberately still counts TARGETS, not messages — it is the caller's answer
        // to "how much work did you accept", and changing its meaning would silently break
        // every client that reports it.
        $queued = 0;

        $hub = TranslateBatchMessage::HUB_LOCALE;
        $spokeLocales = array_values(array_filter(
            array_keys($toDispatch),
            static fn($loc): bool => (string) $loc !== $hub,
        ));

        // ENGLISH HUB. For a non-English source, every spoke is translated from the stored
        // English rather than from the source text, turning 2N-1 inference passes into N —
        // LibreTranslate pivots through English internally anyway, we just never saw it and
        // paid for it once per target language.
        //
        // Two populations, and getting this wrong is silent. The first version keyed the whole
        // decision on `isset($toDispatch[$hub])` and so fell back to the DIRECT route whenever
        // the English was already complete — which is exactly the case worth optimising, since
        // es->en and da->en are both ~100% done. Verified on mus/enterreno: fr and de came back
        // with pivot_locale NULL, i.e. translated from Spanish while a finished English sat
        // unused beside them.
        //
        //   hub PENDING  spokes ride along on the hub leg's thenLocales and are dispatched by
        //                TranslateBatchMessageHandler once the English is committed. Nothing
        //                is dispatched for them here, or they would race the hub and find no
        //                text to read.
        //   hub READY    dispatched immediately with pivotLocale — no hub leg needed, and the
        //                spoke costs one pass instead of two for free.
        //
        // The two sets are disjoint by construction (a Source is in $hubPendingSourceIds or it
        // is not), so nothing is dispatched twice and nothing is dropped.
        if ($from !== $hub && $spokeLocales !== []) {
            $hubKeys = $toDispatch[$hub] ?? [];

            foreach ($this->chunkByChars($hubKeys, $charsByTargetKey) as $chunk) {
                $this->bus->dispatch(
                    new TranslateBatchMessage($from, $hub, $engine, $chunk, thenLocales: $spokeLocales),
                    $stamps,
                );
                $queued += \count($chunk);
            }

            $readyCount = 0;
            foreach ($spokeLocales as $loc) {
                $ready = array_values(array_filter(
                    $toDispatch[$loc],
                    fn(string $key): bool => !isset($hubPendingSourceIds[$sourceIdByTargetKey[$key] ?? null]),
                ));

                foreach ($this->chunkByChars($ready, $charsByTargetKey) as $chunk) {
                    $this->bus->dispatch(
                        // sourceLocale is the HUB, not the original source — that is the pair
                        // the engine is actually being asked for.
                        new TranslateBatchMessage($hub, $loc, $engine, $chunk, pivotLocale: $hub),
                        $stamps,
                    );
                    $queued += \count($chunk);
                }
                $readyCount += \count($ready);

                // The rest are counted as accepted even though their messages do not exist
                // yet — the caller asked for them, and the hub handler will dispatch them.
                $queued += \count($toDispatch[$loc]) - \count($ready);
            }

            $this->logger->info('Lingua intake: english-hub route', [
                'source' => $from,
                'hub' => $hub,
                'spokes' => $spokeLocales,
                'hub_pending' => \count($hubKeys),
                'spokes_pivoting_now' => $readyCount,
            ]);
        } else {
            // Direct route. Either the source IS English (en is already the hub, so every
            // target is a spoke and there is nothing to pivot through), or English was not
            // among the requested targets — in which case pivoting would mean translating
            // into a language the caller never asked for, which is a decision for the caller,
            // not for intake.
            foreach ($toDispatch as $targetLocale => $targetKeys) {
                foreach ($this->chunkByChars($targetKeys, $charsByTargetKey) as $chunk) {
                    $this->bus->dispatch(
                        new TranslateBatchMessage($from, (string) $targetLocale, $engine, $chunk),
                        $stamps,
                    );
                    $queued += \count($chunk);
                }
            }
        }

        // 6b) Start the announce chain. ONE message per batch, never one per target: the
        // handler drains every subscriber in pages and re-schedules itself until nothing is
        // pending, so a 15,000-target push produces a handful of webhooks rather than 15,000.
        if ($subscribed > 0) {
            $this->bus->dispatch(new FlushTranslationNotificationsMessage());
        }

        // 7) Normalize response (sources)
        $items = $this->normalizer->normalize(
            $sources,
            'array',
            ['groups' => ['source.read']]
        );

        $this->logger->info('Lingua intake: done', [
            'source'            => $from,
            'targets'           => $toLocales,
            'engine'            => $engine,
            'texts_in'          => \count($rawTexts),
            'hashes_unique'     => \count($hashes),
            'skipped'           => $skipped,
            'sources_existing'  => \count($existingSources),
            'sources_created'   => $createdSources,
            'targets_existing'  => \count($existingTargets),
            'targets_created'   => $createdTargets,
            'eligible_existing' => $eligibleExisting,
            'queued'            => $queued,
            'subscribed'        => $subscribed,
        ]);

        return [
            'queued'  => $queued,
            'items'   => \is_array($items) ? $items : [],
            'missing' => $missingOut,
        ];
    }

    /**
     * Upsert one subscription per (target, caller) so the caller can be told when it finishes.
     *
     * ## Upsert, not insert
     *
     * Re-pushing the same strings is routine — it is how a client picks up a locale it did not
     * ask for last time, and how `lingua:push` behaves when run twice. The unique constraint is
     * (target, callback_url), so an existing subscription is REUSED and its notifiedAt cleared
     * rather than duplicated. Clearing matters: a client that pushes again is asking to be told
     * again, which is also the supported way to recover from a webhook that was lost before the
     * receiver could apply it.
     *
     * ## Why a ref is required
     *
     * Without the caller's own key there is nothing to name the translation by in the webhook —
     * the caller cannot map lingua's content hash back to its own row without redoing the work
     * `lingua:pull` does. A text with no ref is therefore left unsubscribed (and still
     * translated, still pullable) rather than generating a webhook the caller cannot act on.
     *
     * @param array<string,Target>  $targetByTuple all targets in this batch, by "sourceId|locale|engine"
     * @param array<string,string>  $byHash        source hash => original text
     * @param array<string,string>  $refByText     original text => caller's key
     *
     * @return int subscriptions written
     */
    /**
     * Split target keys into batches bounded by TOTAL CHARACTERS first and count second.
     *
     * array_chunk() sized by count treats a one-word vocabulary term and a paragraph of museum
     * prose as equal units, which they are not — see TRANSLATE_CHUNK_CHARS for why that keeps
     * tripping the engine's idle timeout.
     *
     * A single text larger than the whole budget still gets dispatched, alone, rather than
     * dropped: it will probably be slow, but silently discarding a phrase because it is long
     * would lose data, and a one-key batch is the smallest possible retry if it does fail.
     *
     * Keys with no recorded length count as 0 rather than being skipped. An unmeasured key is a
     * bookkeeping gap, not an empty string, and must still reach the engine — the count cap
     * bounds the damage if many of them land in one batch.
     *
     * @param list<string>       $keys
     * @param array<string,int>  $charsByKey target key => source text length
     *
     * @return \Generator<int, list<string>>
     */
    private function chunkByChars(array $keys, array $charsByKey): \Generator
    {
        $batch = [];
        $chars = 0;

        foreach ($keys as $key) {
            $len = $charsByKey[(string) $key] ?? 0;

            // Flush before adding when this key would push us past either bound. Checked before
            // the append so a batch never exceeds the budget, only ever meets it.
            if ($batch !== [] && ($chars + $len > self::TRANSLATE_CHUNK_CHARS || \count($batch) >= self::TRANSLATE_CHUNK)) {
                yield $batch;
                $batch = [];
                $chars = 0;
            }

            $batch[] = $key;
            $chars += $len;
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    private function subscribe(?string $callbackUrl, array $targetByTuple, array $byHash, array $refByText): int
    {
        $callbackUrl = trim((string) $callbackUrl);
        if ($callbackUrl === '' || $refByText === []) {
            return 0;
        }

        $targets = array_values($targetByTuple);
        if ($targets === []) {
            return 0;
        }

        // One query for every subscription this batch might already have, instead of one
        // findOneBy() per target — a batch is thousands of targets wide.
        /** @var TranslationSubscription[] $existing */
        $existing = $this->em->getRepository(TranslationSubscription::class)->findBy([
            'callbackUrl' => $callbackUrl,
            'target' => $targets,
        ]);

        $byTargetKey = [];
        foreach ($existing as $subscription) {
            $byTargetKey[(string) $subscription->target->key] = $subscription;
        }

        $written = 0;
        $unreferenced = 0;

        foreach ($targets as $target) {
            $hash = (string) $target->source?->hash;
            $text = $byHash[$hash] ?? null;
            $ref = $text === null ? null : ($refByText[$text] ?? null);

            if ($ref === null) {
                $unreferenced++;
                continue;
            }

            $subscription = $byTargetKey[(string) $target->key] ?? null;

            if ($subscription === null) {
                $subscription = new TranslationSubscription($target, $callbackUrl, $ref);
                $this->em->persist($subscription);
            } else {
                $subscription->clientRef = $ref;
            }

            // Announce again even if we already announced once — see the docblock.
            $subscription->notifiedAt = null;
            $written++;
        }

        $this->em->flush();

        if ($unreferenced > 0) {
            $this->logger->info('Lingua intake: {count} target(s) had no caller ref, left unsubscribed', [
                'count' => $unreferenced,
                'callbackUrl' => $callbackUrl,
            ]);
        }

        return $written;
    }
}
