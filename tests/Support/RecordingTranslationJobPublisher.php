<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\TranslationWorker\TranslationJobPublisherInterface;

/** Test double: records the exact plain-JSON body and routing key that would be published. */
final class RecordingTranslationJobPublisher implements TranslationJobPublisherInterface
{
    /** @var list<array{body:string, messageId:string, type:string, routingKey:string}> */
    public array $published = [];

    public ?\Throwable $failWith = null;

    public function publish(string $body, string $messageId, string $type, string $routingKey): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->published[] = ['body' => $body, 'messageId' => $messageId, 'type' => $type, 'routingKey' => $routingKey];
    }
}
