<?php

declare(strict_types=1);

namespace App\Deepl;

/** Finished translations, by source text: what the synchronous endpoint asks before it asks a model. */
interface TranslationMemoryInterface
{
    /**
     * @param list<string> $texts
     *
     * @return array<int, string> translations by the index of their text; texts not in memory are absent
     */
    public function lookup(array $texts, string $source, string $target): array;

    /** Keeps a translation an engine just produced. Does not flush; see flush(). */
    public function remember(string $text, string $source, string $target, string $translation, string $engine): void;

    public function flush(): void;
}
