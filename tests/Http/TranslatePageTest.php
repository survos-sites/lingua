<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Controller\TranslateController;
use App\Translation\NativeEuroNanoEngine;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The translate page and its LibreTranslate-shaped JSON.
 *
 * The page sits behind the login like everything else (first test). The others call the controller itself, so they need
 * neither a signed-in user nor a database.
 */
final class TranslatePageTest extends WebTestCase
{
    public function testThePageNeedsALogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/translate');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testThePageOffersTheEnginesAndTheirLanguages(): void
    {
        $controller = $this->controller(Request::create('/translate'));

        $response = $controller->page();

        self::assertSame(200, $response->getStatusCode());
        $crawler = new Crawler((string) $response->getContent());
        $root = $crawler->filter('[data-controller="translate"]');
        self::assertCount(1, $root);
        self::assertSame('/translate', $root->attr('data-translate-url-value'));
        $engines = json_decode((string) $root->attr('data-translate-engines-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(NativeEuroNanoEngine::NAME, array_key_first($engines));
        self::assertSame(['en'], $engines[NativeEuroNanoEngine::NAME]['targets']);
        self::assertArrayHasKey('libre', $engines);
        $names = json_decode((string) $root->attr('data-translate-names-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('German', $names['de']);
        self::assertGreaterThanOrEqual(2, $crawler->filter('#translate-engine option')->count());
        foreach (['engine', 'source', 'target', 'input', 'output', 'status', 'error', 'count', 'copy', 'swap'] as $target) {
            self::assertCount(1, $crawler->filter("[data-translate-target=\"$target\"]"), "the page has one \"$target\" element");
        }
    }

    public function testTheLanguagesOfAnEngineInLibreTranslatesShape(): void
    {
        $request = Request::create('/translate/languages', 'GET', ['engine' => NativeEuroNanoEngine::NAME]);

        $languages = json_decode((string) $this->controller($request)->languages($request)->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(9, $languages);
        self::assertContains(['code' => 'de', 'name' => 'German', 'targets' => ['en']], $languages);
    }

    public function testTranslatingInProcessThroughTheJsonEndpoint(): void
    {
        $request = $this->json(['q' => "Guten Morgen.\nDas Museum ist geschlossen.", 'source' => 'de', 'target' => 'en', 'engine' => NativeEuroNanoEngine::NAME]);
        $controller = $this->controller($request);
        if (!self::getContainer()->get(NativeEuroNanoEngine::class)->available()) {
            self::markTestSkipped('native engine not available here');
        }

        $response = $controller->translate($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame("Good morning.\nThe museum is closed.", $data['translatedText']);
        self::assertSame(NativeEuroNanoEngine::NAME, $data['engine']);
        self::assertSame(2, $data['meta']['sentences']);
        self::assertNull($data['detectedLanguage']);
    }

    public function testBadRequestsAreAnsweredInWords(): void
    {
        $empty = $this->json(['q' => '  ', 'source' => 'de', 'target' => 'en']);
        $controller = $this->controller($empty);
        self::assertSame(400, $controller->translate($empty)->getStatusCode());

        $unknown = $controller->translate($this->json(['q' => 'Hallo', 'engine' => 'no-such-engine']));
        self::assertSame(400, $unknown->getStatusCode());
        self::assertStringContainsString('Unknown engine', (string) $unknown->getContent());

        $wrongWay = $controller->translate($this->json(['q' => 'Hello', 'source' => 'en', 'target' => 'de', 'engine' => NativeEuroNanoEngine::NAME]));
        self::assertContains($wrongWay->getStatusCode(), [400, 503]); // refused by the engine, or the engine is not installed here
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): Request
    {
        return Request::create('/translate', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
    }

    private function controller(Request $request): TranslateController
    {
        self::bootKernel();
        $request->attributes->set('_route', 'app_translate');
        self::getContainer()->get(RequestStack::class)->push($request);

        return self::getContainer()->get(TranslateController::class);
    }
}
