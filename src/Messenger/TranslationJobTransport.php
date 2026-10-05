<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\TranslationWorker\TranslationJobRequest;
use App\Message\TranslationWorker\WireShape;
use App\Service\TranslationEngineCatalog;
use App\Service\TranslationWorker\TranslationJobPublisherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Send-only transport for {@see TranslationJobRequest}: `$bus->dispatch(new TranslationJobRequest(...))`.
 *
 * Encodes with {@see TranslationWorkerCodec} (plain JSON, no PHP envelope) and publishes to the
 * profile's own routing key with mandatory + confirms. Lingua never consumes jobs, so the
 * receiving half refuses loudly instead of pretending.
 */
final class TranslationJobTransport implements TransportInterface
{
    public function __construct(
        private readonly TranslationJobPublisherInterface $publisher,
        private readonly TranslationEngineCatalog $catalog,
        private readonly TranslationWorkerCodec $codec,
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        $job = $envelope->getMessage();
        if (!$job instanceof TranslationJobRequest) {
            throw new LogicException(\sprintf('The translation jobs transport only sends %s, got %s.', TranslationJobRequest::class, get_debug_type($job)));
        }

        $this->publisher->publish(
            $this->codec->encode($job),
            $job->requestId,
            WireShape::of(TranslationJobRequest::class)->type,
            $this->catalog->worker($job->profile)['routingKey'],
        );

        return $envelope;
    }

    public function get(int $fetchSize = 1): iterable
    {
        throw new LogicException('Translation jobs are consumed by the ai-tools worker, not by Lingua.');
    }

    public function ack(Envelope $envelope): void
    {
        throw new LogicException('Translation jobs are not consumed by Lingua.');
    }

    public function reject(Envelope $envelope): void
    {
        throw new LogicException('Translation jobs are not consumed by Lingua.');
    }
}
