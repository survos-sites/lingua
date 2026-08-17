<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Source;
use App\Entity\Target;
use App\Entity\TranslationSubscription;
use App\Message\FlushTranslationNotificationsMessage;
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
        $toDispatch = []; // targetKey => true
        $createdTargets = 0;
        $eligibleExisting = 0;

        foreach ($toLocales as $loc) {
            foreach ($sources as $source) {
                $tuple = $source->getId().'|'.$loc.'|'.$engine;

                $t = $targetByTuple[$tuple] ?? null;
                if (!$t) {
                    $t = new Target($source, $loc, $engine);
                    $this->em->persist($t);
                    $targetByTuple[$tuple] = $t;

                    $toDispatch[$t->key] = true;
                    $createdTargets++;
                    continue;
                }

                if ($forceDispatch || $t->getMarking() !== TargetWorkflowInterface::PLACE_TRANSLATED) {
                    $toDispatch[$t->key] = true;
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
        // AsyncQueueLocator::stamps() below ALWAYS attaches its own async-routed transport
        // stamp for this transition — that silently overrides the $stamps built above from
        // $payload->transport, so a caller requesting transport=sync never actually got
        // synchronous processing (only ever queued, regardless of what was requested). Force
        // the locator into sync mode first so stamps() honors it instead of routing async.
        if ($payload->transport === 'sync') {
            $this->asyncQueueLocator->sync = true;
        }

        $queued = 0;
        foreach (array_keys($toDispatch) as $targetKey) {
            $msg = new TransitionMessage(
                $targetKey,
                Target::class,
                TargetWorkflowInterface::TRANSITION_TRANSLATE,
                TargetWorkflowInterface::WORKFLOW_NAME
            );

            $queueStamps = $this->asyncQueueLocator->stamps($msg);
            $this->bus->dispatch($msg, array_merge($stamps, $queueStamps));
            $queued++;
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
