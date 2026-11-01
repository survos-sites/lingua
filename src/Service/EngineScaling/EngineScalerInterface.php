<?php

declare(strict_types=1);

namespace App\Service\EngineScaling;

/**
 * Sets how many replicas of the scaled engine app (the `libretranslate` Dokku app) run.
 *
 * Takes a replica COUNT, not on/off, so a future fleet sized by queue depth needs no new
 * contract. Today {@see EngineAutoscaler} only ever asks for 0 or 1.
 *
 * Implementations must be idempotent: asking for the count that is already set is a no-op.
 */
interface EngineScalerInterface
{
    /** @throws EngineScalingException when the request could not be delivered */
    public function scaleTo(int $replicas): void;
}
