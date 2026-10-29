<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Target;
use App\Entity\TranslationSubscription;
use App\Repository\TranslationSubscriptionRepository;
use App\Service\TranslationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;
use Symfony\Component\Webhook\Messenger\SendWebhookMessage;

final class TranslationIntegrationNotifierTest extends TestCase
{
    private const string LOCAL_CALLBACK = 'https://harvest.wip/webhook/lingua';

    private function subscription(string $url): TranslationSubscription
    {
        $target = new Target(targetLocale: 'en', targetText: 'Picture');
        $subscription = new TranslationSubscription($target, $url, 'caller-key');
        $subscription->id = 42;

        return $subscription;
    }

    public function testLocalSubscriberPublishesExistingConsumerMessageWithoutHttp(): void
    {
        $subscription = $this->subscription(self::LOCAL_CALLBACK);
        $repository = $this->createMock(TranslationSubscriptionRepository::class);
        $repository->expects(self::once())->method('pendingFor')->with(self::LOCAL_CALLBACK, TranslationNotifier::PAGE)->willReturn([$subscription]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->willReturnCallback(static function (Envelope $envelope): Envelope {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ConsumeRemoteEventMessage::class, $message);
            self::assertSame('lingua', $message->getType());
            self::assertSame('42-1', $message->getEvent()->getId());
            self::assertSame('Picture', $message->getEvent()->getPayload()['translations'][0]['text']);

            return $envelope;
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $notifier = new TranslationNotifier($repository, $em, $bus, new NullLogger(), integrationCallbackUrl: self::LOCAL_CALLBACK, integrationSender: $sender);
        self::assertSame(1, $notifier->flush(self::LOCAL_CALLBACK));
        self::assertNotNull($subscription->notifiedAt);
    }

    public function testFailedPublishLeavesSubscriptionPending(): void
    {
        $subscription = $this->subscription(self::LOCAL_CALLBACK);
        $repository = $this->createStub(TranslationSubscriptionRepository::class);
        $repository->method('pendingFor')->willReturn([$subscription]);
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willThrowException(new \RuntimeException('Broker unavailable'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $notifier = new TranslationNotifier($repository, $em, $this->createStub(MessageBusInterface::class), new NullLogger(), integrationCallbackUrl: self::LOCAL_CALLBACK, integrationSender: $sender);
        try {
            $notifier->flush(self::LOCAL_CALLBACK);
            self::fail('Expected failed publication.');
        } catch (\RuntimeException $error) {
            self::assertSame('Broker unavailable', $error->getMessage());
            self::assertNull($subscription->notifiedAt);
        }
    }

    public function testOtherSubscribersStillReceiveWebhooks(): void
    {
        $subscription = $this->subscription('https://other.example/webhook/lingua');
        $repository = $this->createStub(TranslationSubscriptionRepository::class);
        $repository->method('pendingFor')->willReturn([$subscription]);
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::isInstanceOf(SendWebhookMessage::class))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $notifier = new TranslationNotifier($repository, $this->createStub(EntityManagerInterface::class), $bus, new NullLogger(), secret: 'test-secret', integrationCallbackUrl: self::LOCAL_CALLBACK, integrationSender: $sender);
        self::assertSame(1, $notifier->flush($subscription->callbackUrl));
    }
}
