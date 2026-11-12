<?php

declare(strict_types=1);

namespace App\Deepl;

use App\Translation\NativeEuroNanoEngine;
use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Service\TranslatorRegistry;

/**
 * Translates a list of texts now, in the request: memory first, then an engine for what memory does not have, and the
 * engine's answers go into memory.
 *
 * Which engine: the one that runs in this process when it handles the pair (no HTTP at all), otherwise the configured
 * default engine. Each answer says where it came from, so a caller can tell a memory hit from a fresh translation.
 */
final class SyncTranslator
{
    public const FROM_MEMORY = 'memory';

    public function __construct(
        private readonly TranslationMemoryInterface $memory,
        private readonly TranslatorRegistry $registry,
        private readonly NativeEuroNanoEngine $native,
    ) {
    }

    /**
     * @param list<string> $texts
     * @param string|null  $source null when the caller did not say: nothing is looked up or remembered then, because
     *                             memory is keyed by the source language
     *
     * @return list<array{text: string, from: string, detected: ?string}> in the order of $texts
     */
    public function translate(array $texts, ?string $source, string $target): array
    {
        $out = array_fill(0, \count($texts), null);
        $missing = [];
        foreach ($texts as $i => $text) {
            if ('' === trim($text) || $source === $target) {
                $out[$i] = ['text' => $text, 'from' => self::FROM_MEMORY, 'detected' => $source];
            } else {
                $missing[$i] = $text;
            }
        }
        if (null !== $source && [] !== $missing) {
            foreach ($this->memory->lookup(array_values($missing), $source, $target) as $position => $translation) {
                $i = array_keys($missing)[$position];
                $out[$i] = ['text' => $translation, 'from' => self::FROM_MEMORY, 'detected' => $source];
                unset($missing[$i]);
            }
        }
        if ([] !== $missing) {
            $engine = $this->engineFor($source ?? 'auto', $target);
            $result = $engine->translateBatch(new TranslationBatchRequest(array_values($missing), $source ?? 'auto', $target));
            $detected = $source ?? ('auto' === $result->detectedSource ? null : $result->detectedSource);
            foreach (array_keys($missing) as $position => $i) {
                $translation = $result->translatedTexts[$position] ?? '';
                $out[$i] = ['text' => $translation, 'from' => $engine->getName(), 'detected' => $detected];
                if (null !== $source && '' !== trim($translation)) {
                    $this->memory->remember($texts[$i], $source, $target, $translation, $engine->getName());
                }
            }
            $this->memory->flush();
        }

        /** @var list<array{text: string, from: string, detected: ?string}> $out */
        return $out;
    }

    private function engineFor(string $source, string $target): TranslatorEngineInterface
    {
        $inProcess = $this->native->available()
            && NativeEuroNanoEngine::TARGET === $target
            && ('auto' === $source || \in_array($source, NativeEuroNanoEngine::SOURCES, true));

        return $inProcess ? $this->native : $this->registry->getDefault();
    }
}
