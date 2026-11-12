<?php

declare(strict_types=1);

namespace App\Translation;

use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Exception\TranslatorException;
use Survos\TranslatorBundle\Model\EngineCapabilities;
use Survos\TranslatorBundle\Model\LanguageDetectionResult;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Model\TranslationBatchResult;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Survos\TranslatorBundle\Model\TranslationResult;

/**
 * EuroNano run in this process (BergamotBridge), as a translator-bundle engine.
 *
 * The rules are those of ai-tools' cv/native_translation.py, which this mirrors: text is split at line breaks and sentence
 * ends, each sentence is translated on its own, and the whitespace between them is kept. One model reads nine European
 * languages and writes English; it is not told which of the nine it is reading.
 */
final class NativeEuroNanoEngine implements TranslatorEngineInterface
{
    public const NAME = 'euronano-native';
    public const MODEL = 'qvac/TranslatePsy-EuroNano';
    /** Languages the xx-en model reads. */
    public const SOURCES = ['cs', 'de', 'es', 'fi', 'fr', 'it', 'nl', 'pt', 'sv'];
    public const TARGET = 'en';
    private const MAX_CHARS = 20000;
    /** ai-tools refuses sentences over 120 model tokens: quality drops beyond what the model was trained on. */
    private const MAX_SENTENCE_TOKENS = 120;
    /** Line breaks, or the space after a sentence end that is followed by a capital. */
    private const BOUNDARIES = '/(\r?\n+|(?<=[.!?])[^\S\r\n]+(?=[A-ZÀ-ÖØ-Ý¿¡]))/u';

    public function __construct(private readonly BergamotBridge $bridge)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function available(): bool
    {
        return $this->bridge->available();
    }

    public function unavailableBecause(): ?string
    {
        return $this->bridge->unavailableBecause();
    }

    public function translate(TranslationRequest $req): TranslationResult
    {
        if ($req->target !== self::TARGET) {
            throw new TranslatorException(sprintf('%s translates into English only, not "%s"', self::NAME, $req->target));
        }
        if ($req->source !== 'auto' && !\in_array($req->source, self::SOURCES, true)) {
            throw new TranslatorException(sprintf('%s reads %s, not "%s"', self::NAME, implode(', ', self::SOURCES), $req->source));
        }
        $text = $req->text;
        if (trim($text) === '' || str_contains($text, "\0")) {
            throw new TranslatorException('Supply nonblank plain text without NUL characters');
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new TranslatorException(sprintf('Input exceeds %d characters', self::MAX_CHARS));
        }

        $began = hrtime(true);
        $out = '';
        $tokens = 0;
        $sentences = 0;
        foreach (preg_split(self::BOUNDARIES, $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            $sentence = trim($part);
            if ($sentence === '') {
                $out .= $part; // the separator itself, kept as written
                continue;
            }
            $result = $this->bridge->translate($sentence);
            // ai-tools counts tokens with SentencePiece before translating; here the engine's own count is checked after.
            if ($result['tokens'] > self::MAX_SENTENCE_TOKENS) {
                throw new TranslatorException(sprintf('A sentence exceeds %d model tokens; split it upstream', self::MAX_SENTENCE_TOKENS));
            }
            if (trim($result['text']) === '') {
                throw new TranslatorException('Model returned empty text');
            }
            if (str_contains($result['text'], '<unk>') || str_contains($result['text'], '⁇')) {
                throw new TranslatorException('Model returned unknown-token markers');
            }
            $leading = substr($part, 0, \strlen($part) - \strlen(ltrim($part)));
            $trailing = substr($part, \strlen(rtrim($part)));
            $out .= $leading . $result['text'] . $trailing;
            $tokens += $result['tokens'];
            ++$sentences;
        }

        return new TranslationResult($out, $req->source === 'auto' ? null : $req->source, [
            'engine' => self::NAME,
            'model' => self::MODEL,
            'variant' => $this->bridge->variant(),
            'runtime' => 'bergamot',
            'elapsed_ms' => round((hrtime(true) - $began) / 1e6, 1),
            'tokens' => $tokens,
            'sentences' => $sentences,
            // 1 for as long as the process lives when the model is kept (FrankenPHP worker, messenger:consume).
            'model_loads' => $this->bridge->usage()['loads'],
            'model_translations' => $this->bridge->usage()['translations'],
        ]);
    }

    public function translateBatch(TranslationBatchRequest $req): TranslationBatchResult
    {
        $texts = [];
        foreach ($req->texts as $text) {
            $texts[] = $this->translate(new TranslationRequest($text, $req->source, $req->target, $req->html))->translatedText;
        }

        return new TranslationBatchResult($texts, $req->source, ['engine' => self::NAME]);
    }

    public function detect(string $text): LanguageDetectionResult
    {
        throw new TranslatorException(self::NAME . ' does not detect languages: one model reads all of its source languages');
    }

    public function capabilities(): EngineCapabilities
    {
        return new EngineCapabilities(supportsGlossary: false, supportsHtml: false, maxCharsPerRequest: self::MAX_CHARS, meta: [
            'sources' => self::SOURCES,
            'targets' => [self::TARGET],
            'in_process' => true,
        ]);
    }
}
