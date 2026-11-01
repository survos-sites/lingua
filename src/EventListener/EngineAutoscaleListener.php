<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\EngineScaling\EngineAutoscaler;
use App\Service\EngineScaling\QueueDepth;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Idle scale-down runs inside the long-lived `translator` worker, which already exists and is
 * woken constantly, instead of adding a cron container to a memory-starved host. The check
 * itself is one cache read, throttled to once a minute.
 */
final class EngineAutoscaleListener
{
    private const int EVERY_SECONDS = 60;
    private const string QUEUE = 'target.translate';

    private float $last = 0.0;

    public function __construct(
        private readonly EngineAutoscaler $autoscaler,
        private readonly QueueDepth $queue,
    ) {
    }

    #[AsEventListener]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle() || !\in_array(self::QUEUE, $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }
        if (microtime(true) - $this->last < self::EVERY_SECONDS) {
            return;
        }
        $this->last = microtime(true);

        $depth = $this->queue->get();
        if ($depth !== null) {
            $this->autoscaler->scaleDownIfIdle($depth);
        }
    }
}
