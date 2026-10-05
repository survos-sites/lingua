<?php

declare(strict_types=1);

namespace App\Service\TranslationWorker;

interface TranslationJobPublisherInterface
{
    /**
     * Publish one plain-JSON body and return only once the broker has confirmed a ROUTED delivery.
     *
     * @throws \Symfony\Component\Messenger\Exception\TransportException when the broker returns, nacks, or does not confirm the publish
     */
    public function publish(string $body, string $messageId, string $type, string $routingKey): void;
}
