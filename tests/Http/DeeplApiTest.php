<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Controller\DeeplController;
use App\Deepl\SyncTranslator;
use App\Deepl\TranslationMemoryInterface;
use App\Translation\BergamotBridge;
use App\Translation\NativeEuroNanoEngine;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Survos\LinguaBundle\Security\LinguaKeyGuard;
use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Service\TranslatorRegistry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lingua answering as DeepL does. Memory is an array and the default engine a mocked LibreTranslate, so this needs no
 * database; the last test uses the real in-process model where it is installed.
 */
final class DeeplApiTest extends TestCase
{
    /** @var list<list<string>|string> what the HTTP engine was asked to translate */
    private array $engineCalls = [];
    private ArrayMemory $memory;

    protected function setUp(): void
    {
        $this->memory = new ArrayMemory();
    }

    public function testTextsAreAnsweredInOrderFromMemoryFirstAndTheEngineForTheRest(): void
    {
        $this->memory->known['en|fr|Log out'] = 'Se déconnecter';

        $response = $this->controller()->translate($this->json(['text' => ['Log in', 'Log out', 'Reset password'], 'source_lang' => 'EN', 'target_lang' => 'FR']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['translations' => [
            ['detected_source_language' => 'EN', 'text' => 'fr:Log in', 'from' => 'libre'],
            ['detected_source_language' => 'EN', 'text' => 'Se déconnecter', 'from' => 'memory'],
            ['detected_source_language' => 'EN', 'text' => 'fr:Reset password', 'from' => 'libre'],
        ]], json_decode((string) $response->getContent(), true));
        self::assertSame([['Log in', 'Reset password']], $this->engineCalls, 'one engine request, only for what memory lacked');
    }

    public function testWhatTheEngineTranslatedIsRememberedSoTheNextRequestDoesNotAskIt(): void
    {
        $controller = $this->controller();
        $request = fn (): Request => $this->json(['text' => ['Reset password'], 'source_lang' => 'EN', 'target_lang' => 'FR']);

        $controller->translate($request());
        $again = json_decode((string) $controller->translate($request())->getContent(), true);

        self::assertSame('memory', $again['translations'][0]['from']);
        self::assertCount(1, $this->engineCalls);
        self::assertSame(1, $this->memory->flushes);
    }

    public function testTheFormEncodingDeeplClientsUseWithRepeatedTextFields(): void
    {
        // As PHP delivers it: the parsed form keeps only the last `text`, the raw body has both.
        $request = Request::create('/v2/translate', 'POST', ['text' => 'Good morning', 'target_lang' => 'DE', 'source_lang' => 'EN'],
            server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            content: 'text=Hello+world&text=Good+morning&target_lang=DE&source_lang=EN');

        $data = json_decode((string) $this->controller()->translate($request)->getContent(), true);

        self::assertSame(['de:Hello world', 'de:Good morning'], array_column($data['translations'], 'text'));
    }

    public function testWithoutASourceLanguageMemoryIsNotUsed(): void
    {
        $this->memory->known['en|fr|Log out'] = 'Se déconnecter';

        $data = json_decode((string) $this->controller()->translate($this->json(['text' => 'Log out', 'target_lang' => 'fr']))->getContent(), true);

        self::assertSame('libre', $data['translations'][0]['from']);
        self::assertSame([], $this->memory->remembered, 'memory is keyed by the source language, so nothing is stored either');
    }

    public function testRegionalCodesAreReducedToTheLanguage(): void
    {
        $data = json_decode((string) $this->controller()->translate($this->json(['text' => 'Hello', 'source_lang' => 'EN', 'target_lang' => 'PT-BR']))->getContent(), true);

        self::assertSame('pt:Hello', $data['translations'][0]['text']);
    }

    public function testBadRequestsGetDeeplsStatusAndAMessage(): void
    {
        $controller = $this->controller();

        self::assertSame(400, $controller->translate($this->json(['target_lang' => 'DE']))->getStatusCode());
        self::assertSame(400, $controller->translate($this->json(['text' => 'Hi']))->getStatusCode());
        self::assertSame(400, $controller->translate($this->json(['text' => 'Hi', 'target_lang' => 'not a language']))->getStatusCode());
        self::assertSame(413, $controller->translate($this->json(['text' => array_fill(0, 51, 'x'), 'target_lang' => 'DE']))->getStatusCode());
        self::assertArrayHasKey('message', json_decode((string) $controller->translate($this->json([]))->getContent(), true));
    }

    public function testTheKeyIsCheckedTheWayDeeplClientsSendIt(): void
    {
        $controller = $this->controller(key: 'secret');
        $body = ['text' => 'Hi', 'source_lang' => 'EN', 'target_lang' => 'DE'];

        self::assertSame(403, $controller->translate($this->json($body))->getStatusCode());
        self::assertSame(403, $controller->translate($this->json($body, 'DeepL-Auth-Key wrong'))->getStatusCode());
        self::assertSame(200, $controller->translate($this->json($body, 'DeepL-Auth-Key secret'))->getStatusCode());
        self::assertSame(403, $controller->usage(Request::create('/v2/usage'))->getStatusCode());
    }

    public function testLanguagesAndUsage(): void
    {
        $controller = $this->controller();

        $languages = json_decode((string) $controller->languages(Request::create('/v2/languages', 'GET', ['type' => 'target']))->getContent(), true);
        self::assertContains(['language' => 'DE', 'name' => 'German', 'supports_formality' => false], $languages);
        self::assertSame(['character_count', 'character_limit'], array_keys(json_decode((string) $controller->usage(Request::create('/v2/usage'))->getContent(), true)));
    }

    public function testIntoEnglishIsTranslatedInThisProcessWithoutTheHttpEngine(): void
    {
        $home = \dirname(__DIR__, 2).'/../ai-tools/var';
        $native = new NativeEuroNanoEngine(new BergamotBridge($home));
        if (!$native->available()) {
            self::markTestSkipped('native engine not available: '.$native->unavailableBecause());
        }

        $data = json_decode((string) $this->controller(native: $native)->translate($this->json(['text' => ['Guten Morgen.', 'Gute Nacht.'], 'source_lang' => 'DE', 'target_lang' => 'EN-US']))->getContent(), true);

        self::assertSame(['Good morning.', 'Good night.'], array_column($data['translations'], 'text'));
        self::assertSame(NativeEuroNanoEngine::NAME, $data['translations'][0]['from']);
        self::assertSame([], $this->engineCalls);
        self::assertCount(2, $this->memory->remembered);
    }

    private function controller(?string $key = null, ?NativeEuroNanoEngine $native = null): DeeplController
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->engineCalls[] = $body['q'];
            $one = static fn (string $q): string => $body['target'].':'.$q;

            return new MockResponse((string) json_encode(['translatedText' => \is_array($body['q']) ? array_map($one, $body['q']) : $one($body['q'])]));
        }, 'https://libre.test');
        $libre = new LibreTranslateEngine($http, 'libre');
        $locator = new class($libre) implements ContainerInterface {
            public function __construct(private readonly LibreTranslateEngine $libre)
            {
            }

            public function get(string $id): mixed
            {
                return $this->libre;
            }

            public function has(string $id): bool
            {
                return 'libre' === $id;
            }
        };
        $translator = new SyncTranslator(
            $this->memory,
            new TranslatorRegistry($locator, ['libre' => 'libre'], 'libre'),
            $native ?? new NativeEuroNanoEngine(new BergamotBridge('/nonexistent')),
        );

        return new DeeplController($translator, new LinguaKeyGuard($key), ['en', 'de', 'fr', 'es']);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, ?string $authorization = null): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + (null === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization]);

        return Request::create('/v2/translate', 'POST', server: $server, content: (string) json_encode($body));
    }
}

final class ArrayMemory implements TranslationMemoryInterface
{
    /** @var array<string, string> "source|target|text" => translation */
    public array $known = [];
    /** @var list<array{string, string, string, string, string}> */
    public array $remembered = [];
    public int $flushes = 0;

    public function lookup(array $texts, string $source, string $target): array
    {
        $out = [];
        foreach ($texts as $i => $text) {
            if (isset($this->known["$source|$target|$text"])) {
                $out[$i] = $this->known["$source|$target|$text"];
            }
        }

        return $out;
    }

    public function remember(string $text, string $source, string $target, string $translation, string $engine): void
    {
        $this->remembered[] = [$text, $source, $target, $translation, $engine];
        $this->known["$source|$target|$text"] = $translation;
    }

    public function flush(): void
    {
        ++$this->flushes;
    }
}
