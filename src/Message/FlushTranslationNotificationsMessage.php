<?php
declare(strict_types=1);

namespace App\Message;

/** Announce a page of committed results; new completions wake another drain. */
final readonly class FlushTranslationNotificationsMessage
{
    public function __construct(
        /** Retained for messages already queued by the former polling implementation. */
        public int $attempt = 0,
    ) {}
}
