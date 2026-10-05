<?php

declare(strict_types=1);

namespace App\Message\TranslationWorker;

/**
 * Stamps a top-level worker message DTO with its wire `type` and shape `version`.
 *
 * The version covers the whole shape, nested DTOs and enums included. Bump it whenever a field
 * is added, removed, renamed or retyped; TranslationWorkerContractTest pins each shape's
 * fingerprint, so a change without a bump fails. Both are sent on the wire as `type` and
 * `schema_version`, and generated into the Python models.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class WireShape
{
    public function __construct(
        public string $type,
        public int $version,
    ) {
    }

    /** @param class-string $class */
    public static function of(string $class): self
    {
        $attributes = new \ReflectionClass($class)->getAttributes(self::class);
        if ($attributes === []) {
            throw new \LogicException($class.' is not a #[WireShape] message.');
        }

        return $attributes[0]->newInstance();
    }
}
