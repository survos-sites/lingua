<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TranslationWorker\TranslationContractExporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('lingua:translation-worker:dump-python', 'Generate the Python (Pydantic v2) models and JSON Schema for the translation worker messages from the PHP DTOs.')]
final class TranslationWorkerDumpPythonCommand
{
    public function __construct(
        private readonly TranslationContractExporter $exporter,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Directory to write lingua_translation_contract.py and translation-worker.schema.json')] ?string $dir = null,
    ): int {
        $dir ??= $this->projectDir.'/var/translation-worker';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $io->error('Cannot create '.$dir);

            return Command::FAILURE;
        }

        $python = $dir.'/lingua_translation_contract.py';
        $schema = $dir.'/translation-worker.schema.json';
        file_put_contents($python, $this->exporter->python());
        file_put_contents($schema, json_encode($this->exporter->jsonSchema(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");

        foreach (TranslationContractExporter::SHAPES as $class) {
            $io->writeln(\sprintf('  %s  %s', $class, $this->exporter->fingerprint($class)));
        }
        $io->success(['Wrote '.$python, 'Wrote '.$schema]);

        return Command::SUCCESS;
    }
}
