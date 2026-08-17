<?php

namespace App\Workflow;

use App\Entity\Target;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

// See events at https://symfony.com/doc/current/workflow.html#using-events

#[Workflow(supports: [Target::class], name: self::WORKFLOW_NAME)]
class TargetWorkflowInterface
{
    // This name is used for injecting the workflow into a service
    // #[Target(TargetWorkflowInterface::WORKFLOW_NAME)] private WorkflowInterface $workflow
    public const WORKFLOW_NAME = 'TargetWorkflow';

    #[Place(initial: true, info: "pending")]
    const PLACE_UNTRANSLATED='u';

    #[Place(info: 'translated')]
    const PLACE_TRANSLATED='t';
    #[Place(info: 'identical', description: "source exists elsewhere?")]
    const PLACE_IDENTICAL='i';
    const PLACES = [self::PLACE_UNTRANSLATED, self::PLACE_TRANSLATED, self::PLACE_IDENTICAL];

    /**
     * Places that mean "there is a result to hand back".
     *
     * IDENTICAL belongs here: the engine returned the source text unchanged, which is a real
     * answer and the subscriber still needs it — a client that only accepted TRANSLATED would
     * wait forever for every proper noun it ever pushed. Anything selecting finished work
     * (notably the webhook flush) must use this rather than PLACE_TRANSLATED alone.
     */
    const TRANSLATED_PLACES = [self::PLACE_TRANSLATED, self::PLACE_IDENTICAL];

    #[Transition([self::PLACE_UNTRANSLATED], self::PLACE_TRANSLATED, async: true)]
    public const TRANSITION_TRANSLATE = 'translate';
}
