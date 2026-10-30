<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Source;
use App\Entity\Target;
use App\Repository\TargetRepository;
use App\Util\HashCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Survos\Lingua\Core\Identity\HashUtil;
#[AsCommand(
    'app:legacy:translations',
    'Import source.dedup.jsonl + target.jsonl into Doctrine (rejecting duplicate keys).'
)]
class ExportTranslationsCommand
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/data/')] private string $dataDir,
        private readonly LoggerInterface                                  $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly TargetRepository $targetRepository,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option(description: 'Limit how many items to process')]
        int $limit = 0,
    ): int {
        return $this->import($io, $limit);
    }

    // ------------------------------------------------------------------
    // IMPORT: JSONL → Doctrine entities
    // ------------------------------------------------------------------
    private function import(SymfonyStyle $io, int $limit): int
    {
        $io->title("Importing JSONL into Doctrine…");

        $sourceFile = $this->dataDir . 'source.dedup.jsonl';
        $targetFile = $this->dataDir . 'target.jsonl';
        $cacheDb = $this->dataDir . 'import-cache.sqlite';
        if (file_exists($cacheDb)) {
            // delete this when we purge!
//            unlink($cacheDb);
        }
        $cache = new HashCache($cacheDb);

        $sourceReader = JsonlReader::open($sourceFile);
        $targetReader = JsonlReader::open($targetFile);

        // ----------------------------------------------
        // PHASE 1: IMPORT SOURCES
        // ----------------------------------------------
        $io->section("Importing sources…");

        $batch = 5000;
        $i = 0;
        assert(file_exists($sourceFile), "Missing $sourceFile");

        // Count lines for progress bar
        $totalSources = (int) trim(shell_exec("wc -l < " . escapeshellarg($sourceFile)));
        $progress = $io->createProgressBar($totalSources);

        foreach ($sourceReader as $row) {
            $progress->advance();
            $i++;

            if ($limit && $i > $limit) break;

            $hash = $row['hash'];

            // skip if exists in cache
            if ($cache->has($hash)) {
                $io->warning("Hash already exists: $hash");
                continue;
            }

            $source = new Source($row['text'], $row['locale'], $hash);
            $this->entityManager->persist($source);

            // mark it so future runs skip it
            $cache->add($hash);

            if ($i % $batch === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        $progress->finish();



// ----------------------------------------------
// PHASE 2: IMPORT TARGETS
// ----------------------------------------------
        $io->section("Importing targets…");

        $sourceRepo = $this->entityManager->getRepository(Source::class);

// Count lines
        $totalTargets = (int) trim(shell_exec("wc -l < " . escapeshellarg($targetFile)));
        $progress = $io->createProgressBar($totalTargets);

        $batch = 5000;
        $i     = 0;

// In-run dedupe set (new key => true)
        $seen = [];

// Existing targets by key (so we don't conflict with old partial runs)
//        $existingKeys = $this->targetRepository->createQueryBuilder('t')
//            ->select('t.key')
//            ->getQuery()
//            ->getSingleColumnResult();
//
//        foreach ($existingKeys as $k) {
//            $seen[$k] = true;
//        }

        foreach ($targetReader as $row) {
            $progress->advance();
            $i++;

            if ($limit && $i > $limit) {
                break;
            }

            $sourceHash = $row['source_hash'];
            $targetLocale = $row['targetLocale'];

            $source = $sourceRepo->findOneBy(['hash' => $sourceHash]);
            if (!$source) {
                // Shouldn't happen if sources are imported correctly
                continue;
            }

            // New canonical key: ignores engine, matches HashUtil and Target::calcKey
            $key = HashUtil::calcTranslationKey($source->hash, $targetLocale, null);

            // Skip if already imported (either previously in DB or earlier in this run)
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // Create Target using new key scheme; ignore JSONL key/engine
            $target = new Target(
                source:       $source,
                targetLocale: $targetLocale,
                engine:       null,
                key:          $key,
            );

            // Direct property updates, no setters
            $target->targetText = $row['text'] ?? null;
            $target->marking    = $row['marking'] ?? null;

            $this->entityManager->persist($target);

            if ($i % $batch === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();

                // NOTE: $seen is just an array of strings, unaffected by clear()
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        $progress->finish();
        return Command::SUCCESS;
    }
}
