<?php

declare(strict_types=1);

namespace App\Message\TranslationWorker;

/**
 * Worker failure. Agreed codes: input_too_long, invalid_request, unsupported_language,
 * profile_mismatch, empty_translation, translation_failed. No stack traces or secrets.
 */
final readonly class TranslationError
{
    public function __construct(
        public string $code,
        public string $message = '',
        public bool $retryable = false,
    ) {
    }
}
