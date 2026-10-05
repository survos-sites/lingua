<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\TranslationEngineCatalog;
use App\Service\TranslationEngineSelector;
use App\Service\TranslationIntakeService;
use App\Repository\{SourceRepository, TargetRepository};
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Model\EngineCapabilities;
use Survos\TranslatorBundle\Service\{TranslatorManager, TranslatorRegistry};
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class TranslationEngineCatalogTest extends TestCase
{
    private function catalog(): TranslationEngineCatalog
    {
        $engine = $this->createStub(TranslatorEngineInterface::class);
        $engine->method('capabilities')->willReturn(new EngineCapabilities());
        $manager = new TranslatorManager(new TranslatorRegistry(new ServiceLocator(['libre' => static fn () => $engine]), ['libre' => 'engine'], 'libre'));
        $http = new MockHttpClient(new MockResponse(json_encode([['code' => 'de', 'targets' => ['en']], ['code' => 'en', 'targets' => ['de']]])));
        return new TranslationEngineCatalog($manager, $http, new ArrayAdapter(), ['libre' => ['provider' => 'libretranslate', 'model' => 'test-model', 'revision' => 'r1', 'languagesUrl' => 'https://example.test/languages', 'headers' => ['Authorization' => 'secret']]]);
    }

    public function testCapabilitiesAreCachedAndCredentialsAreNotReturned(): void
    {
        $catalog = $this->catalog();
        $profile = $catalog->validate('libre', 'de', ['en']);
        self::assertSame('test-model', $profile['model']);
        self::assertSame('r1', $profile['revision']);
        self::assertArrayNotHasKey('headers', $profile);
        self::assertArrayNotHasKey('languagesUrl', $profile);
        self::assertSame($profile, $catalog->validate('libre', 'de', ['en']));
        $this->expectException(\InvalidArgumentException::class);
        $catalog->validate('libre', 'sv', ['en']);
    }

    public function testUnknownEngineAndUnsupportedPairAreRejectedBeforeWritesOrDispatch(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $intake = new TranslationIntakeService($em, $this->createStub(SourceRepository::class), $this->createStub(TargetRepository::class), $bus, $this->createStub(NormalizerInterface::class), new NullLogger(), new AsyncQueueLocator([], [], $em), new TranslationEngineSelector([]), $this->catalog());
        foreach ([['unknown', 'de', 'en'], ['libre', 'sv', 'en'], ['libre', 'de', 'fr']] as [$engine, $source, $target]) {
            $result = $intake->handle(new BatchRequest(source: $source, target: [$target], texts: ['Hallo'], engine: $engine));
            self::assertArrayHasKey('error', $result);
            self::assertSame(0, $result['queued']);
        }
    }
}
