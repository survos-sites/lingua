<?php

declare(strict_types=1);
namespace App\Service;

use Survos\TranslatorBundle\Service\TranslatorManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Public profile metadata is allowlisted; credentials and URLs never leave this service. */
final readonly class TranslationEngineCatalog
{
    public function __construct(
        private TranslatorManager $translators,
        private HttpClientInterface $http,
        private CacheInterface $cache,
        #[Autowire(param: 'translation.engine_profiles')] private array $profiles,
    ) {}

    public function describe(string $engine): array
    {
        if (!in_array($engine, $this->translators->names(), true)) { throw new \InvalidArgumentException('Unknown engine "'.$engine.'". Configured: '.implode(', ', $this->translators->names())); }
        $meta = array_replace($this->translators->by($engine)->capabilities()->meta, $this->profiles[$engine] ?? []);
        return ['engine' => $engine, 'provider' => $meta['provider'] ?? null, 'model' => $meta['model'] ?? null, 'revision' => $meta['revision'] ?? null, 'profileVersion' => $meta['profileVersion'] ?? null];
    }

    public function validate(string $engine, string $source, array $targets): array
    {
        $profile = $this->describe($engine);
        $pairs = $this->languages($engine);
        if (!isset($pairs[$source])) { throw new \InvalidArgumentException(sprintf('Engine "%s" does not support source language "%s".', $engine, $source)); }
        foreach ($targets as $target) {
            if (!in_array($target, $pairs[$source], true)) { throw new \InvalidArgumentException(sprintf('Engine "%s" does not support %s → %s.', $engine, $source, $target)); }
        }
        return $profile;
    }

    /** @return array<string,list<string>> Supported source→target pairs; no guessed support. */
    public function languages(string $engine): array
    {
        $this->describe($engine);
        $config = array_replace($this->translators->by($engine)->capabilities()->meta, $this->profiles[$engine] ?? []);
        if (isset($config['languagePairs'])) { return $config['languagePairs']; }
        if (!isset($config['languagesUrl'])) { throw new \InvalidArgumentException('Language capabilities are not configured for engine "'.$engine.'".'); }
        $key = 'translation_languages_'.hash('sha256', $engine.json_encode([$config['languagesUrl'], $config['targetLanguagesUrl'] ?? null]));
        return $this->cache->get($key, function (ItemInterface $item) use ($config): array {
            $item->expiresAfter(3600);
            $options = ['timeout' => 10, 'max_duration' => 15, 'headers' => $config['headers'] ?? []];
            $sources = $this->http->request('GET', $config['languagesUrl'], $options)->toArray();
            $pairs = [];
            if (isset($config['targetLanguagesUrl'])) {
                $targets = $this->http->request('GET', $config['targetLanguagesUrl'], $options)->toArray();
                $codes = array_map(static fn (array $row): string => strtolower($row['language']), $targets);
                // DeepL accepts legacy base aliases alongside its specific target variants.
                foreach (['en', 'pt', 'zh'] as $alias) {
                    if (array_any($codes, static fn (string $code): bool => str_starts_with($code, $alias.'-'))) { $codes[] = $alias; }
                }
                foreach ($sources as $row) { $pairs[strtolower($row['language'])] = array_values(array_unique($codes)); }
            } else {
                foreach ($sources as $row) { $pairs[strtolower($row['code'])] = array_map('strtolower', $row['targets']); }
            }
            if ($pairs === []) { throw new \RuntimeException('Engine returned no language capabilities.'); }
            return $pairs;
        });
    }
}
