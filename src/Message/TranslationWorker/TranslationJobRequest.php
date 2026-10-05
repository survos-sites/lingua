<?php

declare(strict_types=1);

namespace App\Message\TranslationWorker;

use App\Messenger\TranslationWorkerCodec;

/**
 * Contract v1 `translation.request` — the self-contained job a worker translates.
 *
 * A Messenger message: `$bus->dispatch(new TranslationJobRequest(...))` routes it to the
 * send-only `translation_jobs` transport, which writes it as plain JSON (snake_case wire names,
 * plus `schema_version`/`type` from its #[WireShape], added by {@see TranslationWorkerCodec}). These DTOs are the
 * source of truth; the Python models are generated from them (lingua:translation-worker:dump-python).
 */
#[WireShape(type: 'translation.request', version: 1)]
final readonly class TranslationJobRequest
{
    public function __construct(
        public string $requestId,
        public string $text,
        public string $sourceLocale,
        public string $targetLocale,
        public string $profile,
    ) {
    }
}
