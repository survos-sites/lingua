<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Service\TranslationEngineCatalog;
use App\Service\TranslationWorker\TranslationJobPublisherInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * `translation-jobs://` — the broker connection itself comes from TRANSLATION_WORKER_AMQP_DSN
 * via the publisher, so this DSN carries no credentials and is safe in committed config.
 *
 * @implements TransportFactoryInterface<TranslationJobTransport>
 */
final class TranslationJobTransportFactory implements TransportFactoryInterface
{
    public const string SCHEME = 'translation-jobs://';

    public function __construct(
        private readonly TranslationJobPublisherInterface $publisher,
        private readonly TranslationEngineCatalog $catalog,
        private readonly TranslationWorkerCodec $codec,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new TranslationJobTransport($this->publisher, $this->catalog, $this->codec);
    }

    /** @param array<string, mixed> $options */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::SCHEME);
    }
}
