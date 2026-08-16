<?php
declare(strict_types=1);

namespace App\Tests\RPC;

use App\Entity\Source;
use App\Entity\Target;
use App\Workflow\TargetWorkflowInterface as WF;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * pullTranslations end to end over POST /api/v1, plus the REST route it has to stay
 * compatible with.
 */
final class PullTranslationsMethodTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var array<string,string> */
    private array $hashes = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement('TRUNCATE target, source RESTART IDENTITY CASCADE');

        foreach ([
            ['good morning', 'da', 'god morgen', WF::PLACE_TRANSLATED],
            ['hello world',  'es', 'hola mundo', WF::PLACE_TRANSLATED],
            ['not done yet', 'es', null,         WF::PLACE_UNTRANSLATED],
        ] as [$text, $locale, $translation, $marking]) {
            $source = new Source($text, 'en');
            $this->hashes[$text] = $source->hash;
            $this->em->persist($source);

            $target = new Target($source, $locale, 'libre');
            $target->targetText = $translation;
            $target->setMarking($marking);
            $this->em->persist($target);
        }
        $this->em->flush();
    }

    /** @param array<string,mixed>|list<array<string,mixed>> $payload */
    private function rpc(array $payload): array
    {
        $this->client->request(
            'POST',
            '/api/v1',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $hashes */
    private function call(array $hashes, ?string $locale = null, string|int $id = 1): array
    {
        $params = ['hashes' => $hashes];
        if ($locale !== null) {
            $params['locale'] = $locale;
        }

        return $this->rpc(['jsonrpc' => '2.0', 'method' => 'pullTranslations', 'params' => $params, 'id' => $id]);
    }

    public function testReturnsTranslationsAndAnEmptyMissingList(): void
    {
        $body = $this->call([$this->hashes['good morning']]);

        self::assertResponseIsSuccessful();
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame([$this->hashes['good morning'] => 'god morgen'], $body['result']['translations']);
        self::assertSame([], $body['result']['missing']);
    }

    /**
     * The reason this method was migrated first: the REST route cannot express this. An
     * unknown hash and a known-but-untranslated one are both simply absent from the map.
     */
    public function testMissingSeparatesUnknownAndUntranslatedFromTranslated(): void
    {
        $body = $this->call([
            $this->hashes['good morning'],
            $this->hashes['not done yet'],
            'deadbeefdeadbeef',
        ]);

        self::assertSame([$this->hashes['good morning'] => 'god morgen'], $body['result']['translations']);
        self::assertEqualsCanonicalizing(
            [$this->hashes['not done yet'], 'deadbeefdeadbeef'],
            $body['result']['missing'],
        );
    }

    public function testLocaleFilterIsApplied(): void
    {
        $body = $this->call([$this->hashes['hello world']], locale: 'de');

        self::assertSame([], $body['result']['translations']);
        self::assertSame([$this->hashes['hello world']], $body['result']['missing']);
    }

    /**
     * The round-trip win: several locale groups answered in one request, each keyed by its
     * own id. This is what replaces one HTTP call per chunk per locale pair.
     */
    public function testABatchAnswersEveryElementWithItsOwnId(): void
    {
        $body = $this->rpc([
            ['jsonrpc' => '2.0', 'method' => 'pullTranslations',
             'params' => ['hashes' => [$this->hashes['good morning']], 'locale' => 'da'], 'id' => 'da'],
            ['jsonrpc' => '2.0', 'method' => 'pullTranslations',
             'params' => ['hashes' => [$this->hashes['hello world']], 'locale' => 'es'], 'id' => 'es'],
        ]);

        self::assertCount(2, $body);

        $byId = array_column($body, null, 'id');
        self::assertSame([$this->hashes['good morning'] => 'god morgen'], $byId['da']['result']['translations']);
        self::assertSame([$this->hashes['hello world'] => 'hola mundo'], $byId['es']['result']['translations']);
    }

    /**
     * Regression: a nested array used to satisfy the bundle's `array` type check and then
     * throw in strval(), reaching the caller as -32603 Internal error.
     */
    public function testANonStringHashIsInvalidParamsNotInternalError(): void
    {
        $body = $this->rpc([
            'jsonrpc' => '2.0',
            'method' => 'pullTranslations',
            'params' => ['hashes' => [['a' => 1]]],
            'id' => 'bad',
        ]);

        self::assertSame(-32602, $body['error']['code']);
    }

    public function testAWrongTypeForLocaleIsInvalidParams(): void
    {
        $body = $this->rpc([
            'jsonrpc' => '2.0',
            'method' => 'pullTranslations',
            'params' => ['hashes' => ['abc'], 'locale' => 42],
            'id' => 'bad',
        ]);

        self::assertSame(-32602, $body['error']['code']);
    }

    public function testAnUnknownMethodIsMethodNotFound(): void
    {
        $body = $this->rpc(['jsonrpc' => '2.0', 'method' => 'noSuchMethod', 'id' => 'x']);

        self::assertSame(-32601, $body['error']['code']);
    }

    /**
     * The REST route must keep answering exactly as it does today -- a bare map, no envelope,
     * no `missing` -- because zm/bts/harvest parse it.
     */
    public function testTheRestRouteIsUnchangedAndSharesTheSameService(): void
    {
        $this->client->request(
            'POST',
            '/babel/pull',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'hashes' => [$this->hashes['good morning'], 'deadbeefdeadbeef'],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            [$this->hashes['good morning'] => 'god morgen'],
            json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testTheRestRouteStillRejectsAMissingHashList(): void
    {
        $this->client->request(
            'POST',
            '/babel/pull',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(400);
    }
}
