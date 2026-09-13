<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Target;
use App\EventListener\TranslationCompletionNotifier;
use App\Message\FlushTranslationNotificationsMessage;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TranslationCompletionNotifierTest extends TestCase
{
    public function testCompletedTargetsWakeOneDrainAfterFlushOnly(): void
    {
        $sent = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function ($message) use (&$sent) {
            $sent[] = $message;
            return new Envelope($message);
        });
        $listener = new TranslationCompletionNotifier($bus);
        $em = $this->createStub(EntityManagerInterface::class);
        $pending = new Target();
        $pending->setMarking(TargetWorkflowInterface::PLACE_UNTRANSLATED);
        $listener->postUpdate(new PostUpdateEventArgs($pending, $em));
        $listener->postFlush();
        self::assertSame([], $sent);

        foreach (TargetWorkflowInterface::TRANSLATED_PLACES as $place) {
            $target = new Target();
            $target->setMarking($place);
            $listener->postUpdate(new PostUpdateEventArgs($target, $em));
        }
        self::assertSame([], $sent);
        $listener->postFlush();
        self::assertCount(1, $sent);
        self::assertInstanceOf(FlushTranslationNotificationsMessage::class, $sent[0]);
        $listener->postFlush();
        self::assertCount(1, $sent);
    }
}
