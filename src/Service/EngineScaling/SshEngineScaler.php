<?php

declare(strict_types=1);

namespace App\Service\EngineScaling;

use Symfony\Component\Process\Process;

/**
 * Scales through a RESTRICTED ssh key: the host's authorized_keys pins that key to a
 * forced command (docs/libretranslate-autoscaling.md) that accepts only `scale <0..max>` for
 * one app. Lingua holds no Dokku or root credentials, and the remote command line is built here
 * from an int, never from input.
 */
final readonly class SshEngineScaler implements EngineScalerInterface
{
    public function __construct(
        private string $sshTarget,
        private string $identityFile,
        private int $maxReplicas = 1,
        private int $timeoutSeconds = 30,
    ) {
    }

    public function scaleTo(int $replicas): void
    {
        if ($replicas < 0 || $replicas > $this->maxReplicas) {
            throw new EngineScalingException(\sprintf('Refusing to scale to %d (allowed 0..%d).', $replicas, $this->maxReplicas));
        }

        $process = new Process([
            'ssh',
            '-i', $this->identityFile,
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile='.\dirname($this->identityFile).'/known_hosts',
            '-o', 'ConnectTimeout=10',
            $this->sshTarget,
            'scale '.$replicas,
        ], timeout: $this->timeoutSeconds);

        $process->run();
        if (!$process->isSuccessful()) {
            throw new EngineScalingException(\sprintf('scale %d failed: %s', $replicas, trim($process->getErrorOutput() ?: $process->getOutput())));
        }
    }
}
