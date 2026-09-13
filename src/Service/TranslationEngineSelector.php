<?php
declare(strict_types=1);

namespace App\Service;

use Survos\Lingua\Core\Identity\HashUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TranslationEngineSelector
{
    /** @param array<string, string> $sourceEngines */
    public function __construct(
        #[Autowire(param: 'translation.source_engines')]
        private array $sourceEngines,
    ) {}

    public function select(string $source, ?string $requested): string
    {
        if ($requested !== null) {
            return HashUtil::normalizeEngine(trim($requested));
        }

        $language = explode('-', str_replace('_', '-', strtolower($source)))[0];
        return $this->sourceEngines[$language] ?? 'libre';
    }
}
