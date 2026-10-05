<?php

declare(strict_types=1);

namespace App\Message\TranslationWorker;

/** Which exact model produced a result. Lingua rejects a completed result that disagrees with its profile. */
final readonly class TranslationProvenance
{
    public function __construct(
        public string $model,
        public string $revision,
        public string $variant,
        public ?string $runtime = null,
        public ?string $quantization = null,
        public ?int $beamSize = null,
    ) {
    }
}
