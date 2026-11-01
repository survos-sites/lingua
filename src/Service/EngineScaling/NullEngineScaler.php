<?php

declare(strict_types=1);

namespace App\Service\EngineScaling;

/** Used when autoscaling is off: the engine is assumed to be always running. */
final class NullEngineScaler implements EngineScalerInterface
{
    public function scaleTo(int $replicas): void
    {
    }
}
