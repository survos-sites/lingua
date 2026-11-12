<?php

declare(strict_types=1);

namespace App\Deepl;

use App\Entity\Source;
use App\Entity\Target;
use App\Service\TargetTranslationApplier;
use App\Service\TranslationPullService;
use Doctrine\ORM\EntityManagerInterface;
use Survos\Lingua\Core\Identity\HashUtil;

/**
 * Lingua's own tables as the memory: a Source row per text, a Target row per (source, locale, engine).
 *
 * Reading goes through TranslationPullService, the same query /babel/pull uses, so a text translated by any path
 * (queue worker, batch, this endpoint) is a hit for every other. Writing creates the Source and Target if they are not
 * there and hands the text to TargetTranslationApplier, the one place that decides translated-versus-identical.
 */
final class DoctrineTranslationMemory implements TranslationMemoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslationPullService $pull,
        private readonly TargetTranslationApplier $applier,
    ) {
    }

    public function lookup(array $texts, string $source, string $target): array
    {
        $hashes = [];
        foreach ($texts as $i => $text) {
            $hashes[$i] = HashUtil::calcSourceKey($text, $source);
        }
        $found = $this->pull->pullByHashes(array_values(array_unique($hashes)), $target);
        $out = [];
        foreach ($hashes as $i => $hash) {
            if (isset($found[$hash])) {
                $out[$i] = $found[$hash];
            }
        }

        return $out;
    }

    public function remember(string $text, string $source, string $target, string $translation, string $engine): void
    {
        $hash = HashUtil::calcSourceKey($text, $source);
        $row = $this->em->getRepository(Source::class)->findOneBy(['hash' => $hash]);
        if (null === $row) {
            $row = new Source($text, $source);
            $this->em->persist($row);
        }
        $key = Target::calcKey($row, $target, $engine);
        $translated = $this->em->getRepository(Target::class)->findOneBy(['key' => $key]);
        if (null === $translated) {
            $translated = new Target($row, $target, $engine);
            $this->em->persist($translated);
        }
        $this->applier->apply($translated, $translation, metadata: ['via' => 'sync']);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
