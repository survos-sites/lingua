<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TranslationSubscription;
use App\Repository\TranslationSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Messenger\SendWebhookMessage;
use Symfony\Component\Webhook\Subscriber;

/**
 * Turns finished translations into `translation.completed` webhooks.
 *
 * This is the answer to the question the whole feature exists for: *how does a client know a
 * string has been translated?* Until now it did not — `lingua:pull` polled, which meant either
 * a cron that mostly finds nothing or a human noticing that a locale looks empty.
 *
 * ## Batching is the point
 *
 * One webhook carries up to {@see self::PAGE} translations for one subscriber, with the text
 * inline. Inline rather than "come and fetch them" for two reasons: a translated UI string is
 * a few dozen bytes, so a page of them is smaller than the round trip that would replace it,
 * and a self-contained payload means the client's write path has no dependency on lingua being
 * up at the moment it processes the queue.
 *
 * ## Identity
 *
 * Each row is keyed by `clientRef` — the subscriber's OWN `Str.code`, recorded when it pushed.
 * The receiver writes `StrTranslation` against it directly. Nothing in this payload asks the
 * client to reverse a hash, which is what `lingua:pull` has to do today.
 */
final class TranslationNotifier
{
    /**
     * Translations per webhook. Sized so the body stays well inside any proxy's default body
     * limit while still collapsing a locale's worth of UI strings into a few deliveries.
     */
    public const int PAGE = 500;

    public const string EVENT_TRANSLATION_COMPLETED = 'translation.completed';

    public function __construct(
        private readonly TranslationSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::LINGUA_WEBHOOK_SECRET)%')]
        private readonly ?string $secret = null,
    ) {
    }

    /**
     * Announce one page for every subscriber that has something waiting.
     *
     * @return array{queued:int, translations:int, full:bool} `full` means at least one
     *         subscriber filled a whole page, i.e. there is certainly more to send right now
     */
    public function flushAll(): array
    {
        $queued = 0;
        $translations = 0;
        $full = false;

        foreach ($this->subscriptions->pendingCallbackUrls() as $url) {
            $sent = $this->flush($url);
            if ($sent === 0) {
                continue;
            }
            $queued++;
            $translations += $sent;
            $full = $full || $sent >= self::PAGE;
        }

        return ['queued' => $queued, 'translations' => $translations, 'full' => $full];
    }

    /**
     * Announce one page to one subscriber.
     *
     * @return int translations included (0 = nothing was pending, no webhook queued)
     */
    public function flush(string $callbackUrl): int
    {
        if (($this->secret ?? '') === '') {
            // Fail closed, exactly as mediary does: an unsigned webhook is worse than a late
            // one, because the receiver's whole authorisation story is this secret.
            $this->logger->error('LINGUA_WEBHOOK_SECRET is not set — refusing to send unsigned webhook to {url}', [
                'url' => $callbackUrl,
            ]);

            return 0;
        }

        $pending = $this->subscriptions->pendingFor($callbackUrl, self::PAGE);
        if ($pending === []) {
            return 0;
        }

        $now = new \DateTimeImmutable('now');

        $this->bus->dispatch(new SendWebhookMessage(
            new Subscriber($callbackUrl, $this->secret),
            new RemoteEvent(
                self::EVENT_TRANSLATION_COMPLETED,
                // The id must be stable for THIS delivery and unique across deliveries: it is
                // signed along with the body, and the receiver logs it. First subscription id
                // plus count identifies the page exactly, and — unlike a random uuid — a
                // re-queue of the same page produces the same id, so a duplicate is visibly a
                // duplicate rather than looking like new work.
                \sprintf('%d-%d', $pending[0]->id, \count($pending)),
                $this->payload($pending),
            ),
        ));

        foreach ($pending as $subscription) {
            $subscription->notifiedAt = $now;
        }
        $this->em->flush();

        $this->logger->info('translation.completed queued: {count} → {url}', [
            'count' => \count($pending),
            'url' => $callbackUrl,
        ]);

        return \count($pending);
    }

    /**
     * Manual drain, for when the automatic chain has ended.
     *
     * The chain in {@see \App\MessageHandler\FlushTranslationNotificationsMessageHandler} gives
     * up after an hour of subscriptions that never finish translating, so this is the way back
     * in once the translator is healthy again — and the first thing to run when a subscriber
     * says it is missing translations lingua thinks it sent.
     */
    #[AsCommand('lingua:webhook:flush', 'Send translation.completed webhooks for translations not yet announced')]
    public function flushCommand(
        SymfonyStyle $io,
        #[Option('Keep going until nothing is left to announce, instead of one page per subscriber')]
        bool $all = false,
    ): int {
        $webhooks = 0;
        $translations = 0;

        do {
            $result = $this->flushAll();
            $webhooks += $result['queued'];
            $translations += $result['translations'];
        } while ($all && $result['full']);

        if ($translations === 0) {
            $io->success('Nothing pending — every finished translation has already been announced.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Queued %d webhook(s) carrying %d translation(s).', $webhooks, $translations));
        $io->note('Queued only. Deliver with: bin/console messenger:consume webhook -v');

        return Command::SUCCESS;
    }

    /**
     * @param TranslationSubscription[] $subscriptions
     *
     * @return array<string,mixed>
     */
    private function payload(array $subscriptions): array
    {
        $translations = [];

        foreach ($subscriptions as $subscription) {
            $target = $subscription->target;

            $translations[] = [
                // The subscriber's key, not ours — see the class docblock.
                'ref' => $subscription->clientRef,
                'targetLocale' => $target->targetLocale,
                'engine' => $target->engine,
                'text' => $target->targetText,
                // `identical` is a real outcome, not a failure: the engine returned the source
                // unchanged. Passed through so a client can tell "translated to the same
                // string" from "translated", which matters for review queues.
                'identical' => $target->isIdentical,
                'sourceLocale' => $target->source?->locale,
            ];
        }

        return [
            'event' => self::EVENT_TRANSLATION_COMPLETED,
            'count' => \count($translations),
            'translations' => $translations,
        ];
    }
}
