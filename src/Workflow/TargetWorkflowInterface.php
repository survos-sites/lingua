<?php

namespace App\Workflow;

use App\Entity\Target;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowExtrasBundle\Attribute\Transition;
use Survos\WorkflowMessengerBundle\Attribute\Transition as AsyncTransition;

// See events at https://symfony.com/doc/current/workflow.html#using-events

#[AsWorkflow(name: self::WORKFLOW_NAME, supports: [Target::class], description: 'One translated string for a (source, locale, engine): pending, translated, identical to the source, or queued with an external worker')]
class TargetWorkflowInterface
{
    // This name is used for injecting the workflow into a service
    // #[Target(TargetWorkflowInterface::WORKFLOW_NAME)] private WorkflowInterface $workflow
    public const WORKFLOW_NAME = 'TargetWorkflow';

    #[Place(initial: true, label: 'pending', description: 'No usable result yet. Intake queues translation in batches or through the per-target workflow.')]
    const PLACE_UNTRANSLATED='u';

    #[Place(label: 'translated', description: 'Usable text differs from the source. Persisting this result wakes subscriber notification delivery.')]
    const PLACE_TRANSLATED='t';
    #[Place(label: 'identical', description: 'Usable text equals the source, including proper names. This is a completed result and subscribers are notified.')]
    const PLACE_IDENTICAL='i';

    // Sent to a queue worker profile (dispatch: worker), result not back yet. Never used by the
    // HTTP engines, which go straight from u to t/i.
    #[Place(label: 'queued', description: 'An external translation worker accepted the job; its asynchronous result is still pending. HTTP engines do not enter this place.')]
    const PLACE_QUEUED='q';
    const PLACES = [self::PLACE_UNTRANSLATED, self::PLACE_TRANSLATED, self::PLACE_IDENTICAL, self::PLACE_QUEUED];

    /**
     * Places that mean "there is a result to hand back".
     *
     * IDENTICAL belongs here: the engine returned the source text unchanged, which is a real
     * answer and the subscriber still needs it — a client that only accepted TRANSLATED would
     * wait forever for every proper noun it ever pushed. Anything selecting finished work
     * (notably the webhook flush) must use this rather than PLACE_TRANSLATED alone.
     */
    const TRANSLATED_PLACES = [self::PLACE_TRANSLATED, self::PLACE_IDENTICAL];

    #[AsyncTransition(from: self::PLACE_UNTRANSLATED, to: self::PLACE_TRANSLATED, label: 'translate', description: 'Per-target engine call on target.translate. Bulk intake uses TranslateBatchMessageHandler and the same result applier.', async: true, transport: 'target.translate')]
    public const TRANSITION_TRANSLATE = 'translate';

    /**
     * Worker profiles only: TranslateBatchMessageHandler published the job instead of calling an
     * engine in-process. The result lands via TargetTranslationApplier like every other engine.
     */
    #[Transition(from: self::PLACE_UNTRANSLATED, to: self::PLACE_QUEUED, label: 'dispatch', description: 'Publish a job to an external engine worker; completion arrives separately.')]
    public const TRANSITION_DISPATCH = 'dispatch';

    // A worker result landed on the Target (TranslationResultProcessor). The text is written
    // first; the guards pick the destination. From `u` too: a result can outrun a publish that
    // was reported as failed.
    #[Transition(from: self::PLACE_QUEUED, to: self::PLACE_TRANSLATED, label: 'receive', description: 'Apply a worker result that differs from the source; persist it before notifying subscribers.', guard: 'not subject.hasIdenticalText', metadata: ['guardLabel' => 'differs from source'])]
    #[Transition(from: self::PLACE_UNTRANSLATED, to: self::PLACE_TRANSLATED, label: 'receive', description: 'Apply a worker result that differs from the source; persist it before notifying subscribers.', guard: 'not subject.hasIdenticalText', metadata: ['guardLabel' => 'differs from source'])]
    public const TRANSITION_RECEIVE = 'receive';
    #[Transition(from: self::PLACE_QUEUED, to: self::PLACE_IDENTICAL, label: 'receive identical', description: 'Accept unchanged text as a successful result so subscribers do not wait indefinitely.', guard: 'subject.hasIdenticalText', metadata: ['guardLabel' => 'same as source'])]
    #[Transition(from: self::PLACE_UNTRANSLATED, to: self::PLACE_IDENTICAL, label: 'receive identical', description: 'Accept unchanged text as a successful result so subscribers do not wait indefinitely.', guard: 'subject.hasIdenticalText', metadata: ['guardLabel' => 'same as source'])]
    public const TRANSITION_RECEIVE_IDENTICAL = 'receive_identical';

    // Worker failure, empty result, or a publish the broker did not confirm: back to `u`, so the
    // next push sends it again. No callback fires.
    #[Transition(from: self::PLACE_QUEUED, to: self::PLACE_UNTRANSLATED, label: 'reject', description: 'Return a failed, empty or unconfirmed worker job to pending. No completion notification is emitted.')]
    public const TRANSITION_REJECT = 'reject';
}
