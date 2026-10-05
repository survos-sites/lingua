<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Source;
use App\Entity\Target;
use App\Workflow\TargetWorkflowInterface as Workflow;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicTranslationApiTest extends WebTestCase
{
    #[DataProvider('statuses')]
    public function testAnonymousAgentsCanReadTranslationStatus(string $marking, string $resource): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->beginTransaction();

        try {
            $source = new Source('Public API probe '.bin2hex(random_bytes(8)), 'en');
            $target = new Target($source, 'fr', 'libre');
            $target->setMarking($marking);
            $em->persist($source);
            $em->persist($target);
            $em->flush();

            $uri = match ($resource) {
                'collection' => '/api/targets?'.http_build_query([
                    'key' => $target->key,
                    'targetLocale' => 'fr',
                    'engine' => 'libre',
                    'marking' => $marking,
                ]),
                'target' => '/api/targets/'.$target->key,
                'source' => '/api/sources/'.$source->hash,
            };
            $client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if ($resource === 'collection') {
                self::assertCount(1, $data);
                $data = $data[0];
            }
            if ($resource !== 'source') {
                self::assertSame($marking, $data['marking']);
                self::assertSame($target->key, $data['key']);
            } else {
                self::assertSame($source->hash, $data['hash']);
            }
        } finally {
            $em->getConnection()->rollBack();
        }
    }

    public static function statuses(): iterable
    {
        foreach (['collection', 'target', 'source'] as $resource) {
            yield $resource.' pending' => [Workflow::PLACE_UNTRANSLATED, $resource];
            yield $resource.' translated' => [Workflow::PLACE_TRANSLATED, $resource];
            yield $resource.' identical' => [Workflow::PLACE_IDENTICAL, $resource];
        }
    }

    public function testResourceRoutesExposeOnlyReads(): void
    {
        self::bootKernel();
        $count = 0;
        foreach (self::getContainer()->get('router')->getRouteCollection() as $route) {
            if (preg_match('#^/api/(sources|targets)(?:[/{.]|$)#', $route->getPath())) {
                ++$count;
                self::assertNotEmpty($route->getMethods());
                self::assertSame([], array_diff($route->getMethods(), ['GET', 'HEAD']));
            }
        }
        self::assertSame(4, $count);
    }
}
