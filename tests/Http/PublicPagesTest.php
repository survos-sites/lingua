<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicPagesTest extends WebTestCase
{
    #[DataProvider('pages')]
    public function testPublicPage(string $path, int $status): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame($status);
    }

    public static function pages(): iterable
    {
        yield 'health' => ['/health', 200];
        yield 'login renders assets' => ['/login', 200];
        yield 'status' => ['/status.json', 200];
        yield 'empty pull request' => ['/babel/pull', 400];
    }
}
