<?php

declare(strict_types=1);

namespace App\Service\TranslationWorker;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publishes plain-JSON jobs with publisher confirms AND `mandatory`; used by the send-only
 * {@see \App\Messenger\TranslationJobTransport}.
 *
 * Not jwage/phpamqplib-messenger's AMQP transport: it cannot publish
 * mandatory, and with confirms alone a job sent to a routing key nobody has bound (worker
 * queue not declared yet, typo in a profile's routingKey) is acked by the broker and silently
 * dropped. Here a basic.return, a nack, or a confirm timeout all throw, so the outbox message
 * whose handler dispatched the job retries and finally lands in the Doctrine `failed` transport.
 *
 * The exchange is NOT declared here — see messenger.yaml `translation_results`, which owns it.
 */
#[AsAlias(TranslationJobPublisherInterface::class)]
final class AmqpTranslationJobPublisher implements TranslationJobPublisherInterface
{
    private ?AbstractConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public function __construct(
        #[Autowire('%env(default::TRANSLATION_WORKER_AMQP_DSN)%')]
        private readonly ?string $dsn,
        #[Autowire('%translation.worker_exchange%')]
        private readonly string $exchange,
        private readonly float $confirmTimeout = 10.0,
    ) {
    }

    public function publish(string $body, string $messageId, string $type, string $routingKey): void
    {
        $returned = null;
        $nacked = false;

        try {
            $channel = $this->channel();
            $channel->set_return_listener(static function (int $replyCode, string $replyText) use (&$returned): void {
                $returned = $replyCode.' '.$replyText;
            });
            $channel->set_nack_handler(static function () use (&$nacked): void {
                $nacked = true;
            });

            $channel->basic_publish(new AMQPMessage($body, [
                'content_type' => 'application/json',
                'content_encoding' => 'utf-8',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $messageId,
                'type' => $type,
            ]), $this->exchange, $routingKey, true);

            $channel->wait_for_pending_acks_returns($this->confirmTimeout);
        } catch (\Throwable $e) {
            // A channel that failed mid-confirm cannot be trusted for the next job.
            $this->close();
            throw new \RuntimeException(\sprintf('Publishing translation job %s failed: %s', $messageId, $e->getMessage()), 0, $e);
        }

        if ($returned !== null) {
            throw new \RuntimeException(\sprintf('Translation job %s was unroutable on %s/%s (%s); is the worker queue declared?', $messageId, $this->exchange, $routingKey, $returned));
        }
        if ($nacked) {
            throw new \RuntimeException(\sprintf('Broker nacked translation job %s.', $messageId));
        }
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel?->is_open()) {
            return $this->channel;
        }
        if (($this->dsn ?? '') === '') {
            throw new \RuntimeException('TRANSLATION_WORKER_AMQP_DSN is not configured.');
        }

        $this->close();
        $this->connection = AMQPConnectionFactory::create(self::config($this->dsn));
        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();

        return $this->channel;
    }

    private static function config(string $dsn): AMQPConnectionConfig
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            throw new \RuntimeException('TRANSLATION_WORKER_AMQP_DSN is not a valid URL.');
        }

        $config = new AMQPConnectionConfig();
        $config->setHost($parts['host']);
        $config->setPort((int) ($parts['port'] ?? 5672));
        $config->setUser(rawurldecode($parts['user'] ?? 'guest'));
        $config->setPassword(rawurldecode($parts['pass'] ?? 'guest'));
        $config->setVhost(rawurldecode(trim($parts['path'] ?? '/', '/')) ?: '/');
        $config->setConnectionName('lingua-translation-job-publisher');

        return $config;
    }

    private function close(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable) {
            // Already broken; nothing left to release.
        }
        $this->channel = null;
        $this->connection = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
