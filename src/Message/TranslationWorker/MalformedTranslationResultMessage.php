<?php

declare(strict_types=1);

namespace App\Message\TranslationWorker;

/**
 * A result body that failed contract validation. Decoding into this, rather than throwing,
 * lets the handler reject it as unrecoverable so it lands in the Doctrine `failed` transport
 * — inspectable and retryable by hand — instead of being nacked and redelivered forever.
 */
final readonly class MalformedTranslationResultMessage
{
    public function __construct(
        public string $reason,
        /** First bytes of the body, for diagnosis only. */
        public string $bodyExcerpt,
    ) {
    }
}
