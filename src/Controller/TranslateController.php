<?php

declare(strict_types=1);

namespace App\Controller;

use App\Translation\NativeEuroNanoEngine;
use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Survos\TranslatorBundle\Service\TranslatorRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A translate page in the manner of LibreTranslate's: type on the left, read on the right, pick the languages and the engine.
 *
 * The JSON endpoints take and return what LibreTranslate's do (POST /translate with q, source, target; GET /languages), plus an
 * `engine`. Engines are the ones configured in survos_translator (libre, deepl, ...) and, where its files are present, EuroNano
 * running inside this PHP process (App\Translation\NativeEuroNanoEngine).
 *
 * Like every page that is not listed in security.yaml, this needs a login.
 */
final class TranslateController extends AbstractController
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        private readonly TranslatorRegistry $registry,
        private readonly NativeEuroNanoEngine $native,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
    ) {
    }

    #[Route('/translate', name: 'app_translate', methods: ['GET'])]
    public function page(): Response
    {
        $engines = $this->engines();

        $names = [];
        foreach ($engines as $engine) {
            foreach ([...$engine['sources'], ...$engine['targets']] as $code) {
                $names[$code] ??= self::languageName($code);
            }
        }

        return $this->render('translate/index.html.twig', [
            'engines' => $engines,
            'names' => $names,
            'defaultEngine' => $this->native->available() ? NativeEuroNanoEngine::NAME : $this->registry->defaultName(),
        ]);
    }

    /** LibreTranslate's GET /languages, per engine: [{code, name, targets}]. */
    #[Route('/translate/languages', name: 'app_translate_languages', methods: ['GET'])]
    public function languages(Request $request): JsonResponse
    {
        $engine = $request->query->getString('engine', $this->registry->defaultName());
        $info = $this->engines()[$engine] ?? null;
        if ($info === null) {
            return $this->json(['error' => "Unknown engine: $engine"], Response::HTTP_BAD_REQUEST);
        }
        $out = [];
        foreach ($info['sources'] as $code) {
            $out[] = ['code' => $code, 'name' => self::languageName($code), 'targets' => array_values(array_diff($info['targets'], [$code]))];
        }

        return $this->json($out);
    }

    /** LibreTranslate's POST /translate: {q, source, target} in, {translatedText} out; `engine` picks who translates. */
    #[Route('/translate', name: 'app_translate_api', methods: ['POST'])]
    public function translate(Request $request): JsonResponse
    {
        $in = $request->getContentTypeFormat() === 'json' ? $request->toArray() : $request->request->all();
        $text = (string) ($in['q'] ?? '');
        $source = (string) ($in['source'] ?? 'auto');
        $target = (string) ($in['target'] ?? 'en');
        $name = (string) ($in['engine'] ?? $this->registry->defaultName());
        if (trim($text) === '') {
            return $this->json(['error' => 'Nothing to translate: q is empty'], Response::HTTP_BAD_REQUEST);
        }
        $info = $this->engines()[$name] ?? null;
        if ($info === null) {
            return $this->json(['error' => "Unknown engine: $name"], Response::HTTP_BAD_REQUEST);
        }
        if (!$info['available']) {
            return $this->json(['error' => "$name is not available here: {$info['reason']}"], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $began = hrtime(true);
        try {
            $result = $this->engine($name)->translate(new TranslationRequest($text, $source, $target));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage(), 'engine' => $name], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'translatedText' => $result->translatedText,
            'detectedLanguage' => $result->detectedSource === null || $source !== 'auto' ? null : ['language' => $result->detectedSource],
            'engine' => $name,
            'ms' => round((hrtime(true) - $began) / 1e6, 1),
            'meta' => $result->meta,
        ]);
    }

    private function engine(string $name): TranslatorEngineInterface
    {
        return $name === NativeEuroNanoEngine::NAME ? $this->native : $this->registry->get($name);
    }

    /**
     * Every engine the page can offer, the in-process one first.
     *
     * @return array<string, array{label: string, available: bool, reason: ?string, inProcess: bool, sources: list<string>, targets: list<string>, auto: bool}>
     */
    private function engines(): array
    {
        $engines = [NativeEuroNanoEngine::NAME => [
            'label' => 'EuroNano (in this PHP process)',
            'available' => $this->native->available(),
            'reason' => $this->native->unavailableBecause(),
            'inProcess' => true,
            'sources' => NativeEuroNanoEngine::SOURCES,
            'targets' => [NativeEuroNanoEngine::TARGET],
            'auto' => true, // one model reads all nine: it does not need to be told which
        ]];
        // The configured engines are remote services and their language lists are theirs to say; the page offers this
        // application's own locales plus what EuroNano reads, and the service answers for anything it does not support.
        $codes = array_values(array_unique([...$this->enabledLocales, ...NativeEuroNanoEngine::SOURCES]));
        sort($codes);
        foreach ($this->registry->names() as $name) {
            $engines[$name] = [
                'label' => $name . ' (HTTP)',
                'available' => true,
                'reason' => null,
                'inProcess' => false,
                'sources' => $codes,
                'targets' => $codes,
                'auto' => true,
            ];
        }

        return $engines;
    }

    private static function languageName(string $code): string
    {
        return Languages::exists($code) ? ucfirst(Languages::getName($code, 'en')) : $code;
    }
}
