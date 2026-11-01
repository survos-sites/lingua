<?php

declare(strict_types=1);

namespace App\Service\EngineScaling;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/** Ready-message count of the translate queue; null when the transport cannot say. */
final readonly class QueueDepth
{
    public function __construct(private object $transport)
    {
    }

    public function get(): ?int
    {
        return $this->transport instanceof MessageCountAwareInterface ? $this->transport->getMessageCount() : null;
    }
}
