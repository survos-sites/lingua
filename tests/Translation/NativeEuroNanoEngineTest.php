<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\BergamotBridge;
use App\Translation\NativeEuroNanoEngine;
use PHPUnit\Framework\TestCase;
use Survos\TranslatorBundle\Exception\TranslatorException;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Model\TranslationRequest;

/**
 * EuroNano translating inside the test process, through FFI. Needs ai-tools' native engine files beside this checkout
 * (BERGAMOT_HOME); skipped where they are absent, which is everywhere but a development Mac today.
 */
final class NativeEuroNanoEngineTest extends TestCase
{
    private NativeEuroNanoEngine $engine;

    protected function setUp(): void
    {
        $home = $_SERVER['BERGAMOT_HOME'] ?? $_ENV['BERGAMOT_HOME'] ?? \dirname(__DIR__, 2) . '/../ai-tools/var';
        $this->engine = new NativeEuroNanoEngine(new BergamotBridge(str_replace('%kernel.project_dir%', \dirname(__DIR__, 2), $home)));
    }

    public function testASentenceIsTranslatedInProcess(): void
    {
        $this->needsTheEngine();

        $result = $this->engine->translate(new TranslationRequest('Guten Morgen.', 'de', 'en'));

        self::assertSame('Good morning.', $result->translatedText);
        self::assertSame('de', $result->detectedSource);
        self::assertSame('euronano-native', $result->meta['engine']);
        self::assertSame(1, $result->meta['sentences']);
        self::assertGreaterThan(0, $result->meta['tokens']);
    }

    public function testOneModelReadsEverySourceLanguageWithoutBeingTold(): void
    {
        $this->needsTheEngine();

        foreach (['fr' => 'Le musée ferme le lundi.', 'es' => 'El museo cierra los lunes.', 'it' => 'Il museo chiude il lunedì.'] as $text) {
            $out = $this->engine->translate(new TranslationRequest($text, 'auto', 'en'))->translatedText;
            self::assertStringContainsStringIgnoringCase('museum', $out);
            self::assertStringContainsStringIgnoringCase('monday', $out);
        }
    }

    public function testSentencesAreTranslatedOneByOneAndTheSpacingBetweenThemIsKept(): void
    {
        $this->needsTheEngine();

        $result = $this->engine->translate(new TranslationRequest("Guten Morgen. Wie geht es dir?\n\n  Das Museum ist geschlossen.", 'de', 'en'));

        self::assertSame(3, $result->meta['sentences']);
        self::assertMatchesRegularExpression('/^Good morning\. How are you\?\n\n  The museum is closed\.$/', $result->translatedText);
    }

    public function testABatchKeepsItsOrder(): void
    {
        $this->needsTheEngine();

        $result = $this->engine->translateBatch(new TranslationBatchRequest(['Guten Morgen.', 'Gute Nacht.'], 'de', 'en'));

        self::assertSame(['Good morning.', 'Good night.'], $result->translatedTexts);
    }

    public function testItSaysWhatItCannotDoBeforeTouchingTheModel(): void
    {
        // No engine files needed: these are refused on the request alone.
        foreach ([
            ['Hello.', 'en', 'de', 'English only'],
            ['Привет.', 'ru', 'en', 'not "ru"'],
            ['   ', 'de', 'en', 'nonblank'],
        ] as [$text, $source, $target, $message]) {
            try {
                $this->engine->translate(new TranslationRequest($text, $source, $target));
                self::fail("expected a refusal for $source to $target");
            } catch (TranslatorException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    private function needsTheEngine(): void
    {
        if (!$this->engine->available()) {
            self::markTestSkipped('native engine not available: ' . $this->engine->unavailableBecause());
        }
    }
}
