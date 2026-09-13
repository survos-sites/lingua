<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Target;
use App\Message\FlushTranslationNotificationsMessage;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class TranslationCompletionNotifier
{
    private bool $completed = false;

    public function __construct(private readonly MessageBusInterface $bus) {}

    public function postPersist(PostPersistEventArgs $event): void { $this->remember($event->getObject()); }
    public function postUpdate(PostUpdateEventArgs $event): void { $this->remember($event->getObject()); }

    private function remember(object $object): void
    {
        if ($object instanceof Target && in_array($object->getMarking(), TargetWorkflowInterface::TRANSLATED_PLACES, true)) {
            $this->completed = true;
        }
    }

    public function postFlush(): void
    {
        if (!$this->completed) { return; }
        $this->completed = false;
        $this->bus->dispatch(new FlushTranslationNotificationsMessage());
    }
}
