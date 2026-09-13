<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Message\FlushTranslationNotificationsMessage;
use App\MessageHandler\FlushTranslationNotificationsMessageHandler;
use App\Repository\TranslationSubscriptionRepository;
use App\Service\TranslationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotificationDrainBatchTest extends TestCase
{
    public function testFailedScanRejectsEveryWakeupForRetry(): void
    {
        $error = new \RuntimeException('Database unavailable');
        $subscriptions = $this->createMock(TranslationSubscriptionRepository::class);
        $subscriptions->expects(self::once())->method('pendingCallbackUrls')->willThrowException($error);
        $bus = $this->createStub(MessageBusInterface::class);
        $notifier = new TranslationNotifier($subscriptions, $this->createStub(EntityManagerInterface::class), $bus, new NullLogger());
        $handler = new FlushTranslationNotificationsMessageHandler($notifier, $bus);
        $acks = [];
        for ($i = 0; $i < 3; ++$i) {
            $acks[] = $ack = new Acknowledger($handler::class);
            $handler(new FlushTranslationNotificationsMessage(), $ack);
        }
        $handler->flush(true);
        foreach ($acks as $ack) {
            self::assertTrue($ack->isAcknowledged());
            self::assertSame($error, $ack->getError());
        }
    }

    public function testRepeatedWakeupsShareOneScanAndAllGetAcknowledged(): void
    {
        $subscriptions = $this->createMock(TranslationSubscriptionRepository::class);
        $subscriptions->expects(self::once())->method('pendingCallbackUrls')->willReturn([]);
        $bus = $this->createStub(MessageBusInterface::class);
        $notifier = new TranslationNotifier($subscriptions, $this->createStub(EntityManagerInterface::class), $bus, new NullLogger());
        $handler = new FlushTranslationNotificationsMessageHandler($notifier, $bus);
        $acks = [];
        for ($i = 0; $i < 100; ++$i) {
            $acks[] = $ack = new Acknowledger($handler::class);
            $handler(new FlushTranslationNotificationsMessage(), $ack);
        }
        foreach ($acks as $ack) {
            self::assertTrue($ack->isAcknowledged());
            self::assertNull($ack->getError());
        }
    }
}
