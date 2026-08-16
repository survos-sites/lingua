<?php
declare(strict_types=1);

namespace App\Tests\RPC;

use App\RPC\V1\PullTranslations\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hand-written element check on `hashes`.
 *
 * This exists because the bundle compiles its validators from *property types* only and
 * ignores Assert attributes on the DTO (its docs/validation.md). `array` is as deep as that
 * check goes, so `{"hashes":[{"a":1}]}` satisfied it and then threw inside strval() further
 * down, reaching the caller as -32603 Internal error instead of -32602 Invalid params.
 *
 * No kernel: this is the DTO's own contract.
 */
final class PullTranslationsRequestTest extends TestCase
{
    public function testAcceptsAListOfStrings(): void
    {
        $request = new Request(['abc123', 'def456'], 'es', 'libre');

        self::assertSame(['abc123', 'def456'], $request->getHashes());
        self::assertSame('es', $request->getLocale());
        self::assertSame('libre', $request->getEngine());
    }

    public function testFiltersDefaultToNullSoEveryLocaleAndEngineMatch(): void
    {
        $request = new Request(['abc123']);

        self::assertNull($request->getLocale());
        self::assertNull($request->getEngine());
    }

    /**
     * A \TypeError is the bundle's documented signal for bad input -- it catches one from the
     * constructor and converts it to -32602. Asserting the type is the point of the test.
     */
    #[DataProvider('nonStringElements')]
    public function testRejectsANonStringElement(mixed $bad, string $expectedTypeName): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(sprintf('hashes[0] must be of type string, %s given.', $expectedTypeName));

        new Request([$bad]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function nonStringElements(): iterable
    {
        // The nested array is the case that actually reached production behaviour as -32603.
        yield 'nested array' => [['a' => 1], 'array'];
        yield 'int'          => [42, 'int'];
        yield 'null'         => [null, 'null'];
        yield 'bool'         => [true, 'bool'];
        yield 'float'        => [1.5, 'float'];
    }

    public function testRejectsABadElementAmongGoodOnesAndNamesItsIndex(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('hashes[1] must be of type string, int given.');

        new Request(['abc123', 7, 'def456']);
    }

    /** The setter is the path the hydrator uses, so it must enforce the same rule. */
    public function testTheSetterEnforcesTheSameRule(): void
    {
        $request = new Request(['abc123']);

        $this->expectException(\TypeError::class);
        $request->setHashes(['ok', ['nope']]);
    }

    public function testAnEmptyListIsAllowedHereAndHandledDownstream(): void
    {
        // Not an error at the DTO level: pullByHashes() short-circuits an empty list, and the
        // method still answers with empty translations and empty missing.
        self::assertSame([], (new Request([]))->getHashes());
    }
}
