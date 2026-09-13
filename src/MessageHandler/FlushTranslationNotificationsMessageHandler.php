<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FlushTranslationNotificationsMessage;
use App\Service\TranslationNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/** Drain committed translations; future results wake this worker after their flush. */
#[AsMessageHandler]
final class FlushTranslationNotificationsMessageHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(private readonly TranslationNotifier $notifier, private readonly MessageBusInterface $bus) {}

    public function __invoke(FlushTranslationNotificationsMessage $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    private function getBatchSize(): int { return 100; }

    private function process(array $jobs): void
    {
        try {
            if ($this->notifier->flushAll()['full']) {
                $this->bus->dispatch(new FlushTranslationNotificationsMessage());
            }
        } catch (\Throwable $error) {
            foreach ($jobs as [, $ack]) { $ack->nack($error); }
            return;
        }

        foreach ($jobs as [, $ack]) { $ack->ack(); }
    }
}
