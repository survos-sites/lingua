<?php

declare(strict_types=1);

namespace App\Message\TranslationWorker;

use App\Messenger\TranslationWorkerCodec;

/**
 * Contract v1 `translation.result`, published by the worker as plain JSON and decoded into this
 * DTO by {@see TranslationWorkerCodec}. Shape is checked there; identity against the stored job
 * is checked by the result handler.
 */
#[WireShape(type: 'translation.result', version: 1)]
final readonly class TranslationResultMessage
{
    /**
     * @param array<string, int|float> $metrics
     */
    public function __construct(
        public string $requestId,
        public string $profile,
        public string $sourceLocale,
        public string $targetLocale,
        public TranslationStatus $status,
        public ?string $translatedText = null,
        public ?TranslationProvenance $provenance = null,
        public array $metrics = [],
        public ?TranslationError $error = null,
        public ?float $confidence = null,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->status === TranslationStatus::Completed;
    }
}
