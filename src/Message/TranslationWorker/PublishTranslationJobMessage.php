<?php

declare(strict_types=1);

namespace App\Message\TranslationWorker;

/**
 * Outbox entry: "publish TranslationJob $requestId to the worker broker".
 *
 * Routed to a Doctrine transport on the default connection, so it is inserted in the SAME
 * transaction as the TranslationJob row. Publishing happens in its handler, after commit,
 * and is retried by Messenger until the broker confirms a routed delivery.
 */
final readonly class PublishTranslationJobMessage
{
    public function __construct(public string $requestId)
    {
    }
}
