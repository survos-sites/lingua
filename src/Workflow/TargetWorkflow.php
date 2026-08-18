<?php

namespace App\Workflow;

use App\Entity\Target;
use App\Service\TargetTranslationApplier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\DebugUtils\Assert;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Survos\TranslatorBundle\Service\TranslatorManager;
use Survos\StateBundle\Attribute\Workflow;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use App\Workflow\TargetWorkflowInterface as WF;

// See events at https://symfony.com/doc/current/workflow.html#using-events

// @todo: add the entity class to attach this to.
final class TargetWorkflow
{

    public function __construct(
        private readonly EntityManagerInterface             $entityManager,
        private readonly TranslatorManager $manager,
        private readonly TargetTranslationApplier          $applier,
        private LoggerInterface                             $logger
    )
    {
    }

    #[AsGuardListener(WF::WORKFLOW_NAME)]
    public function onGuard(GuardEvent $event): void
    {
        // switch ($event->getTransition()) { ...
    }
    private function getTarget(Event $event): Target
    {
        /** @var Target */ return $event->getSubject();
    }


    #[AsTransitionListener(WF::WORKFLOW_NAME, TargetWorkflowInterface::TRANSITION_TRANSLATE)]
    public function onTransition(TransitionEvent $event): void
    {
        $target = $this->getTarget($event);

        if ($target->isTranslated) {
            $this->logger->info("Already translated '{$target->key}'");
//            return; // already translated, probably queued multiple times
        }

        $source = $target->source;
        $engine = $target->engine;
        Assert::inArray($engine, $this->manager->names(), __CLASS__);
        $translator = $this->manager->by($engine);
        assert($translator, "missing translator");
        if (!$translator) {
            return;
        }
        $targetLocale = $target->targetLocale;
        $sourceText = $source->getText();
        $from = $source->locale;
        // info, not warning: this fires once per string translated (routine, not an
        // anomaly) — needs -v to see, same as any other progress-narration log.
        $this->logger->info(sprintf('[%s->%s] %s', $from, $targetLocale, TargetTranslationApplier::snippet($sourceText)));
        $response = $translator->translate(new TranslationRequest(
            $sourceText,
            $source->locale,
            $targetLocale,
        ));

        // Writing the result — and deciding translated-vs-identical — belongs to
        // TargetTranslationApplier, which TranslateBatchMessageHandler also calls. One string
        // per HTTP request is the SLOW path; it stays because every TransitionMessage already
        // in the queue still arrives here, but the two must not disagree about what a
        // translation means. See App\Message\TranslateBatchMessage.
        $this->applier->apply($target, $response->translatedText);

        $this->entityManager->flush();
    }

}
