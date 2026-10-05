<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Message\TranslationWorker\TranslationJobRequest;
use App\Message\TranslationWorker\TranslationResultMessage;
use App\Message\TranslationWorker\WireShape;
use App\Service\TranslationWorker\TranslationContractExporter;
use PHPUnit\Framework\TestCase;

/**
 * Shape drift guard. If this fails you changed a worker message DTO (or something nested in it):
 *
 *   1. bump `version` in that DTO's #[WireShape],
 *   2. update the pin below to the new version + fingerprint,
 *   3. bin/console lingua:translation-worker:dump-python, and re-vendor the .py into ai-tools.
 *
 * The worker must run models generated for the same versions, or Lingua rejects its messages.
 */
final class TranslationWorkerContractTest extends TestCase
{
    private const array PINNED = [
        TranslationJobRequest::class => ['translation.request', 1, '90b72e5f18798b481b52b12a725b828530b437e72be8c2d3f0f1a9664a60b579'],
        TranslationResultMessage::class => ['translation.result', 1, '9212460ff1a926d2521eef400968b37e75f73e2d6fd0aa8072c32a0dad49cc3c'],
    ];

    public function testEveryShapeIsPinnedAtItsVersion(): void
    {
        $exporter = new TranslationContractExporter();
        self::assertSame(array_keys(self::PINNED), TranslationContractExporter::SHAPES);

        foreach (self::PINNED as $class => [$type, $version, $fingerprint]) {
            $shape = WireShape::of($class);
            self::assertSame([$type, $version], [$shape->type, $shape->version], $class);
            self::assertSame($fingerprint, $exporter->fingerprint($class), $class.' changed shape: bump its #[WireShape] version and re-pin (see class docblock).');
        }
    }

    public function testGeneratedPythonCarriesVersionsAndFingerprints(): void
    {
        $exporter = new TranslationContractExporter();
        $python = $exporter->python();

        foreach (self::PINNED as [$type, $version, $fingerprint]) {
            self::assertStringContainsString(\sprintf('"%s": %d,', $type, $version), $python);
            self::assertStringContainsString(\sprintf('"%s": "%s",', $type, $fingerprint), $python);
            self::assertStringContainsString(\sprintf('    type: Literal["%s"]'."\n", $type), $python);
            self::assertStringContainsString(\sprintf('    schema_version: Literal[%d]'."\n", $version), $python);
        }
        self::assertStringContainsString('metrics: dict[str, float] = Field(default_factory=dict)', $python);
        self::assertStringNotContainsString('completed:', $python, 'methods must never leak into the wire shape');
    }
}
