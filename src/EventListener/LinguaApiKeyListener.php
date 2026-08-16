<?php
declare(strict_types=1);

namespace App\EventListener;

use Survos\LinguaBundle\Security\LinguaKeyGuard;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Guards lingua's machine-facing endpoints with the shared key.
 *
 * lingua answers anonymously today: `/batch-translate` creates rows and can spend DeepL
 * money, `/babel/pull` reads the whole translation memory, and `/api/v1` now exposes
 * pullTranslations. Verified against production on 2026-08-16 -- an anonymous POST to
 * /babel/pull returns 200.
 *
 * Deliberately inert until LINGUA_API_KEY is set: {@see LinguaKeyGuard::check()} allows
 * everything when no key is configured. Turning it on is a deployment step -- set the same
 * value on lingua and on zm/bts/harvest in one go -- not a code change, so this can land
 * without locking anyone out.
 *
 * Not a Symfony firewall: these are service-to-service calls with a shared secret and no
 * user, session or role. A firewall would buy the security stack's ceremony for a string
 * comparison. If per-tenant keys or roles ever arrive, that is the moment to promote this
 * to a real authenticator.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final readonly class LinguaApiKeyListener
{
    /**
     * Path prefixes that carry data or cost money. The admin UI, API Platform's read-only
     * resources and the workflow dashboards are out of scope here -- they are a separate
     * question (they are also open, which is worth fixing, but not by this listener).
     */
    private const GUARDED_PREFIXES = ['/api/v', '/batch-translate', '/babel/pull'];

    public function __construct(private LinguaKeyGuard $guard)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->guard->isConfigured()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        $guarded = false;
        foreach (self::GUARDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $guarded = true;
                break;
            }
        }

        if (!$guarded || $this->guard->check($event->getRequest())) {
            return;
        }

        // JSON-RPC callers get an envelope they can parse; -32000 is the spec's
        // implementation-defined server-error range, since JSON-RPC has no auth code.
        // id is null because the body has not been parsed at this point.
        $body = str_starts_with($path, '/api/v')
            ? ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Unauthorized.'], 'id' => null]
            : ['status' => 'error', 'error' => 'Unauthorized.'];

        $event->setResponse(new JsonResponse($body, JsonResponse::HTTP_UNAUTHORIZED));
    }
}
