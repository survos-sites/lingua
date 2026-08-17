<?php

declare(strict_types=1);

namespace App\Message;

/**
 * "Announce whatever has finished translating."
 *
 * Deliberately carries no target: this is a DRAIN, not a notification about one string, and
 * that distinction is the whole reason it exists.
 *
 * The obvious implementation — dispatch one of these from the translate transition — floods.
 * A single `lingua:push` of 5,000 strings into three locales is 15,000 transitions, and one
 * message (and one HTTP delivery) per transition is exactly the failure that buried mediary's
 * queue when every workflow transition dispatched its own Meilisearch index job. Subscribers do
 * not want 15,000 webhooks either; they want a handful of batches.
 *
 * So the transition stays silent and this message coalesces instead: each run announces up to
 * a page of finished translations per subscriber, then re-dispatches itself — immediately when
 * a page came back full (there is more ready right now), or after a delay when subscriptions
 * are still waiting on the translator. It stops on its own when nothing is pending, so the
 * steady state is an empty queue rather than a poll.
 *
 * @see \App\MessageHandler\FlushTranslationNotificationsMessageHandler
 */
final class FlushTranslationNotificationsMessage
{
    public function __construct(
        /**
         * How many times this chain has already run. Bounds the self-rescheduling above: a
         * target that never reaches a finished place (engine permanently down, a locale no
         * translator supports) would otherwise keep the chain alive forever. When the cap is
         * hit the chain simply ends — `lingua:webhook:flush` is the manual way back in, and a
         * later batch starts a fresh chain anyway.
         */
        public readonly int $attempt = 0,
    ) {
    }
}
