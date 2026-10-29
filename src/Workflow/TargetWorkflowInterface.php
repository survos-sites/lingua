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

    #[Place(initial: true, info: 'pending', description: 'No usable result yet. Intake queues translation in batches or through the per-target workflow.')]
    const PLACE_UNTRANSLATED='u';

    #[Place(info: 'translated', description: 'Usable text differs from the source. Persisting this result wakes subscriber notification delivery.')]
    const PLACE_TRANSLATED='t';
    #[Place(info: 'identical', description: 'Usable text equals the source, including proper names. This is a completed result and subscribers are notified.')]
    const PLACE_IDENTICAL='i';

    // Sent to a queue worker profile (dispatch: worker), result not back yet. Never used by the
    // HTTP engines, which go straight from u to t/i.
    #[Place(info: 'queued', description: 'An external translation worker accepted the job; its asynchronous result is still pending. HTTP engines do not enter this place.')]
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

    #[Transition([self::PLACE_UNTRANSLATED], self::PLACE_TRANSLATED, async: true, info: 'translate', description: 'Per-target engine call on target.translate. Bulk intake uses TranslateBatchMessageHandler and the same result applier.')]
    public const TRANSITION_TRANSLATE = 'translate';

    /**
     * Worker profiles only: TranslateBatchMessageHandler published the job instead of calling an
     * engine in-process. The result lands via TargetTranslationApplier like every other engine.
     */
    #[Transition([self::PLACE_UNTRANSLATED], self::PLACE_QUEUED, info: 'dispatch', description: 'Publish a job to an external engine worker; completion arrives separately.')]
    public const TRANSITION_DISPATCH = 'dispatch';

    // A worker result landed on the Target (TranslationResultProcessor). The text is written
    // first; the guards pick the destination. From `u` too: a result can outrun a publish that
    // was reported as failed.
    #[Transition([self::PLACE_QUEUED, self::PLACE_UNTRANSLATED], self::PLACE_TRANSLATED, guard: 'not subject.hasIdenticalText', guardLabel: 'differs from source', info: 'receive', description: 'Apply a worker result that differs from the source; persist it before notifying subscribers.')]
    public const TRANSITION_RECEIVE = 'receive';
    #[Transition([self::PLACE_QUEUED, self::PLACE_UNTRANSLATED], self::PLACE_IDENTICAL, guard: 'subject.hasIdenticalText', guardLabel: 'same as source', info: 'receive identical', description: 'Accept unchanged text as a successful result so subscribers do not wait indefinitely.')]
    public const TRANSITION_RECEIVE_IDENTICAL = 'receive_identical';

    // Worker failure, empty result, or a publish the broker did not confirm: back to `u`, so the
    // next push sends it again. No callback fires.
    #[Transition([self::PLACE_QUEUED], self::PLACE_UNTRANSLATED, info: 'reject', description: 'Return a failed, empty or unconfirmed worker job to pending. No completion notification is emitted.')]
    public const TRANSITION_REJECT = 'reject';
}
