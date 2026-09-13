<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TranslationEngineSelector;
use PHPUnit\Framework\TestCase;

final class TranslationEngineSelectorTest extends TestCase
{
    public function testConfiguredSourceUsesAvailableEngineWithoutOverridingCaller(): void
    {
        $selector = new TranslationEngineSelector(['hr' => 'deepl']);
        self::assertSame('deepl', $selector->select('hr', null));
        self::assertSame('deepl', $selector->select('hr-HR', null));
        self::assertSame('libre', $selector->select('pl', null));
        self::assertSame('libre', $selector->select('hr', 'libre'));
    }
}
