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
    /**
     * A profile with `dispatch: worker` is served by an out-of-process queue worker rather than
     * a TranslatorManager HTTP engine. It has no translator instance, so its language pairs are
     * configured statically — the worker, not Lingua, decides which pairs it supports.
     */
    public const string DISPATCH_WORKER = 'worker';

    public function __construct(
        private TranslatorManager $translators,
        private HttpClientInterface $http,
        private CacheInterface $cache,
        #[Autowire(param: 'translation.engine_profiles')] private array $profiles,
    ) {}

    /** @return list<string> every engine/profile name a request may select */
    public function names(): array
    {
        $workers = array_keys(array_filter($this->profiles, static fn (array $p): bool => ($p['dispatch'] ?? null) === self::DISPATCH_WORKER));

        return array_values(array_unique([...$this->translators->names(), ...array_map('strval', $workers)]));
    }

    public function isWorker(string $engine): bool
    {
        return ($this->profiles[$engine]['dispatch'] ?? null) === self::DISPATCH_WORKER;
    }

    /**
     * Internal worker identity and routing. Not public output: describe() stays the allowlist.
     *
     * @return array{provider:?string, model:?string, revision:?string, profileVersion:?string, variant:?string, routingKey:string}
     */
    public function worker(string $engine): array
    {
        if (!$this->isWorker($engine)) { throw new \InvalidArgumentException('Engine "'.$engine.'" is not a worker profile.'); }
        $p = $this->profiles[$engine];
        if (($p['routingKey'] ?? '') === '') { throw new \InvalidArgumentException('Worker profile "'.$engine.'" has no routingKey.'); }

        return ['provider' => $p['provider'] ?? null, 'model' => $p['model'] ?? null, 'revision' => $p['revision'] ?? null, 'profileVersion' => $p['profileVersion'] ?? null, 'variant' => $p['variant'] ?? null, 'routingKey' => $p['routingKey']];
    }

    public function describe(string $engine): array
    {
        if ($this->isWorker($engine)) {
            $meta = $this->profiles[$engine];
        } else {
            if (!in_array($engine, $this->translators->names(), true)) { throw new \InvalidArgumentException('Unknown engine "'.$engine.'". Configured: '.implode(', ', $this->names())); }
            $meta = array_replace($this->translators->by($engine)->capabilities()->meta, $this->profiles[$engine] ?? []);
        }
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
        if ($this->isWorker($engine)) {
            if (!isset($this->profiles[$engine]['languagePairs'])) { throw new \InvalidArgumentException('Worker profile "'.$engine.'" must configure languagePairs.'); }
            return $this->profiles[$engine]['languagePairs'];
        }
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
