<?php

declare(strict_types=1);

namespace App\Service\EngineScaling;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Keeps the self-hosted LibreTranslate app at the replica count the work needs, 0 when idle.
 *
 * State (last activity, last scale request) lives in a SHARED cache pool: intake runs in the web
 * container and the handler in the translator container, and neither can see the other's
 * filesystem.
 *
 * Only 0 and 1 are implemented. {@see replicasFor()} is the one place a queue-depth sized fleet
 * would change; the scaler contract and the command already speak in replica counts.
 */
final class EngineAutoscaler
{
    private const string KEY_ACTIVITY = 'engine_autoscale.last_activity';
    private const string KEY_REQUESTED = 'engine_autoscale.last_scale_request';

    /** Do not repeat a scale request more often than this while waiting for the model to load. */
    private const int RESCALE_AFTER_SECONDS = 120;

    /**
     * @param list<string> $scaledEngines engine/profile names served by the scaled app
     */
    public function __construct(
        private readonly EngineScalerInterface $scaler,
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $state,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly string $healthUrl,
        private readonly array $scaledEngines = ['libre'],
        private readonly int $idleMinutes = 10,
        private readonly int $readyTimeoutSeconds = 180,
        private readonly int $maxReplicas = 1,
        private readonly int $pollSeconds = 5,
        private readonly \Closure|null $sleep = null,
    ) {
    }

    public function manages(string $engine): bool
    {
        return $this->enabled && \in_array($engine, $this->scaledEngines, true);
    }

    /** Record that the engine was just used (or is about to be). */
    public function touch(): void
    {
        $this->write(self::KEY_ACTIVITY, $this->now());
    }

    /**
     * Non-blocking: called when work is queued so the model loads while the queue drains toward
     * the worker. Safe to call per request; scale requests are throttled.
     */
    public function requestUp(int $queueDepth = 1): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->touch();
        $this->scaleToThrottled($this->replicasFor($queueDepth, idle: false));
    }

    /**
     * Blocking: returns once the engine answers its health URL.
     *
     * @throws EngineNotReadyException when it is still starting after the ready timeout
     */
    public function ensureReady(): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->touch();
        if ($this->isHealthy()) {
            return;
        }

        $this->scaleToThrottled($this->replicasFor(1, idle: false));

        $deadline = $this->now() + $this->readyTimeoutSeconds;
        while ($this->now() < $deadline) {
            $this->sleepSeconds($this->pollSeconds);
            if ($this->isHealthy()) {
                $this->touch();
                $this->logger->info('engine ready');

                return;
            }
        }

        throw new EngineNotReadyException(
            \sprintf('Engine not healthy %ds after scale-up; will retry.', $this->readyTimeoutSeconds),
            retryDelay: 30_000,
        );
    }

    /**
     * Scale to 0 when the queue is empty and nothing used the engine for the idle window.
     *
     * @return bool true when a scale-down was issued
     */
    public function scaleDownIfIdle(int $queueDepth): bool
    {
        if (!$this->enabled || $queueDepth > 0) {
            return false;
        }

        $last = $this->read(self::KEY_ACTIVITY);
        if ($last === null) {
            // No record at all (fresh cache): start the idle clock now instead of killing an
            // engine that may be mid-batch.
            $this->touch();

            return false;
        }

        if ($this->now() - $last < $this->idleMinutes * 60) {
            return false;
        }

        $replicas = $this->replicasFor(0, idle: true);
        $this->scaler->scaleTo($replicas);
        // Forget the last scale-UP request, or the throttle would swallow the next one.
        $this->state->deleteItem(self::KEY_REQUESTED);
        $this->logger->info('engine idle {idle}s with empty queue, scaled to {n}', ['idle' => $this->now() - $last, 'n' => $replicas]);

        return true;
    }

    /** The target replica count. 0/1 only for now; a fleet would derive it from $queueDepth. */
    public function replicasFor(int $queueDepth, bool $idle): int
    {
        if ($idle || $queueDepth <= 0) {
            return 0;
        }

        return min(1, $this->maxReplicas);
    }

    public function isHealthy(): bool
    {
        try {
            return $this->http->request('GET', $this->healthUrl, ['timeout' => 5, 'max_duration' => 8])->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    private function scaleToThrottled(int $replicas): void
    {
        $last = $this->read(self::KEY_REQUESTED);
        if ($last !== null && $this->now() - $last < self::RESCALE_AFTER_SECONDS) {
            return;
        }

        try {
            $this->scaler->scaleTo($replicas);
        } catch (EngineScalingException $e) {
            // Do not throttle a failed request: the next caller should try again.
            $this->logger->error('engine scale to {n} failed: {m}', ['n' => $replicas, 'm' => $e->getMessage()]);

            return;
        }
        $this->write(self::KEY_REQUESTED, $this->now());
        $this->logger->info('engine scale to {n} requested', ['n' => $replicas]);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function sleepSeconds(int $s): void
    {
        $this->sleep !== null ? ($this->sleep)($s) : sleep($s);
    }

    private function read(string $key): ?int
    {
        $item = $this->state->getItem($key);

        return $item->isHit() ? (int) $item->get() : null;
    }

    private function write(string $key, int $value): void
    {
        $this->state->save($this->state->getItem($key)->set($value));
    }
}
