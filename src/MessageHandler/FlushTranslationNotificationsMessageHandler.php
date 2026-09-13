<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FlushTranslationNotificationsMessage;
use App\Service\TranslationNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/** Drain committed translations; future results wake this worker after their flush. */
#[AsMessageHandler]
final readonly class FlushTranslationNotificationsMessageHandler
{
    public function __construct(private TranslationNotifier $notifier, private MessageBusInterface $bus) {}

    public function __invoke(FlushTranslationNotificationsMessage $message): void
    {
        if ($this->notifier->flushAll()['full']) {
            $this->bus->dispatch(new FlushTranslationNotificationsMessage());
        }
    }
}
