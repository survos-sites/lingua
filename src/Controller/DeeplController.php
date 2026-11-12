<?php

declare(strict_types=1);

namespace App\Controller;

use App\Deepl\SyncTranslator;
use Survos\LinguaBundle\Security\LinguaKeyGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lingua behind DeepL's API shape, so anything that can talk to DeepL can talk to lingua by changing the server URL:
 * the official deeplcom/deepl-php client (`server_url`), translator-bundle's `deepl` engine type (`base_uri`), CAT tools.
 *
 *   POST /v2/translate   text (one or many), target_lang, source_lang  ->  {translations: [{detected_source_language, text}]}
 *   GET  /v2/languages   ?type=source|target
 *   GET  /v2/usage
 *
 * Unlike /batch-translate this answers in the request: translation memory first, an engine for the rest (SyncTranslator).
 * Each translation also carries `from` ("memory" or the engine's name), which DeepL clients ignore.
 *
 * The key is lingua's own (LINGUA_API_KEY), sent the way DeepL clients send theirs: `Authorization: DeepL-Auth-Key <key>`.
 * As everywhere in lingua, no configured key means no check. Options DeepL has and lingua does not (formality, glossary,
 * tag handling, context) are accepted and ignored.
 */
final class DeeplController
{
    /** DeepL caps a request at 50 texts. */
    private const MAX_TEXTS = 50;

    /** @param list<string> $enabledLocales */
    public function __construct(
        private readonly SyncTranslator $translator,
        private readonly LinguaKeyGuard $guard,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
    ) {
    }

    #[Route('/v2/translate', name: 'deepl_translate', methods: ['POST'])]
    public function translate(Request $request): JsonResponse
    {
        if (null !== ($denied = $this->denied($request))) {
            return $denied;
        }
        $in = $this->input($request);
        $texts = $in['text'] ?? null;
        $texts = \is_string($texts) ? [$texts] : $texts;
        if (!\is_array($texts) || [] === $texts || array_filter($texts, static fn (mixed $t): bool => !\is_string($t))) {
            return $this->error('Parameter "text" not specified or not a string or list of strings.');
        }
        if (\count($texts) > self::MAX_TEXTS) {
            return $this->error(sprintf('Too many texts: at most %d per request.', self::MAX_TEXTS), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $target = self::locale($in['target_lang'] ?? null);
        if (null === $target) {
            return $this->error('Value for "target_lang" not supported.');
        }
        $source = isset($in['source_lang']) && '' !== $in['source_lang'] ? self::locale($in['source_lang']) : null;
        if (isset($in['source_lang']) && '' !== $in['source_lang'] && null === $source) {
            return $this->error('Value for "source_lang" not supported.');
        }

        try {
            $translations = $this->translator->translate(array_values($texts), $source, $target);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['translations' => array_map(static fn (array $t): array => [
            'detected_source_language' => strtoupper($t['detected'] ?? ''),
            'text' => $t['text'],
            'from' => $t['from'],
        ], $translations)]);
    }

    #[Route('/v2/languages', name: 'deepl_languages', methods: ['GET', 'POST'])]
    public function languages(Request $request): JsonResponse
    {
        if (null !== ($denied = $this->denied($request))) {
            return $denied;
        }
        $target = 'target' === ($request->query->getString('type') ?: $request->request->getString('type', 'source'));
        $out = [];
        foreach ($this->enabledLocales as $code) {
            $entry = ['language' => strtoupper($code), 'name' => Languages::exists($code) ? ucfirst(Languages::getName($code, 'en')) : $code];
            $out[] = $target ? $entry + ['supports_formality' => false] : $entry;
        }

        return new JsonResponse($out);
    }

    /** Lingua does not meter characters; DeepL's own convention for "no limit" is a limit of 10^12. */
    #[Route('/v2/usage', name: 'deepl_usage', methods: ['GET', 'POST'])]
    public function usage(Request $request): JsonResponse
    {
        return $this->denied($request) ?? new JsonResponse(['character_count' => 0, 'character_limit' => 1_000_000_000_000]);
    }

    private function denied(Request $request): ?JsonResponse
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        $key = 0 === stripos($authorization, 'DeepL-Auth-Key ') ? trim(substr($authorization, 15)) : $this->guard->presentedKey($request);

        return $this->guard->isValid($key) ? null : $this->error('Authorization failed. Please supply a valid DeepL-Auth-Key via the Authorization header.', Response::HTTP_FORBIDDEN);
    }

    /**
     * DeepL takes JSON, or a form in which `text` is repeated once per text.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        if ('json' === $request->getContentTypeFormat()) {
            try {
                return $request->toArray();
            } catch (\Throwable) {
                return [];
            }
        }
        $in = $request->request->all();
        // PHP keeps only the last of several `text=` fields; read them all from the raw body.
        if (preg_match_all('/(?:^|&)text=([^&]*)/', $request->getContent(), $m) && \count($m[1]) > 1) {
            $in['text'] = array_map('urldecode', $m[1]);
        }

        return $in;
    }

    /** "DE", "en-US", "pt-BR" -> the two-letter locale lingua keys on; null for anything that is not a language code. */
    private static function locale(mixed $code): ?string
    {
        return \is_string($code) && preg_match('/^([a-z]{2})(?:[-_][a-z]{2,4})?$/i', trim($code), $m) ? strtolower($m[1]) : null;
    }

    private function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
