<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Workflow;

use Survos\AiWorkflowBundle\Traits\PendingStepsInterface;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowMessengerBundle\Attribute\Transition;

/**
 * Shared AI lifecycle definition.
 *
 * Places are durable milestones. Apps store in-progress task queues on the
 * subject, then use guards/listeners to decide when the next transition is
 * allowed. Business policy belongs in app listeners.
 */
#[AsWorkflow(supports: [PendingStepsInterface::class], name: self::WORKFLOW_NAME)]
final class SubjectFlow
{
    public const WORKFLOW_NAME = 'ai_subject';

    #[Place(
        initial: true,
        label: 'Subject registered',
        description: 'The subject exists but has not been prepared for AI work.',
        metadata: ['bg_color' => 'secondary'],
        next: [self::TRANSITION_PREPARE],
    )]
    public const PLACE_NEW = 'new';

    #[Place(
        label: 'Prepared for AI work',
        description: 'Source content, metadata, URLs, or normalized text are available for observation.',
        metadata: ['bg_color' => 'primary'],
        next: [self::TRANSITION_OBSERVE],
    )]
    public const PLACE_PREPARED = 'prepared';

    #[Place(
        label: 'Observed evidence exists',
        description: 'Observation has produced neutral evidence. Additional observation tasks may still be queued on the subject.',
        metadata: ['bg_color' => 'primary'],
        next: [self::TRANSITION_OBSERVE, self::TRANSITION_ANALYZE],
    )]
    public const PLACE_OBSERVED = 'observed';

    #[Place(
        label: 'Analysis complete',
        description: 'Application-level interpretation has been derived from observed evidence and context.',
        metadata: ['bg_color' => 'success'],
        next: [self::TRANSITION_ANALYZE, self::TRANSITION_REVIEW],
    )]
    public const PLACE_ANALYZED = 'analyzed';

    #[Place(
        label: 'Reviewed',
        description: 'Analysis has passed app-defined review, whether automatic policy checks or human approval.',
        metadata: ['bg_color' => 'success'],
        next: [self::TRANSITION_PUBLISH],
    )]
    public const PLACE_REVIEWED = 'reviewed';

    #[Place(
        label: 'Published',
        description: 'Analysis has been projected into app fields, indexes, exports, webhooks, or other public outputs.',
        metadata: ['bg_color' => 'success'],
    )]
    public const PLACE_PUBLISHED = 'published';

    #[Transition(
        from: self::PLACE_NEW,
        to: self::PLACE_PREPARED,
        async: true,
        transport: 'ai_subject.prepare',
        label: 'Prepare Metadata/Image',
        description: 'App-owned setup: fetch source, normalize content, create media variants, resolve URLs, or load metadata.',
    )]
    public const TRANSITION_PREPARE = 'prepare';

    #[Transition(
        from: self::PLACE_PREPARED,
        to: self::PLACE_OBSERVED,
        async: true,
        transport: 'ai_subject.observe',
        guard: "subject.pendingCount(constant('Survos\\\\AiWorkflowBundle\\\\Workflow\\\\SubjectFlow::TRANSITION_OBSERVE')) > 0",
        label: 'Observation Tasks',
        description: 'Generate neutral evidence from prepared content. For media this starts with low-res observation plus optional high-res reads; for text it may be summaries or structural facts.',
    )]
    #[Transition(
        from: self::PLACE_OBSERVED,
        to: self::PLACE_OBSERVED,
        async: true,
        transport: 'ai_subject.observe',
        guard: "subject.pendingCount(constant('Survos\\\\AiWorkflowBundle\\\\Workflow\\\\SubjectFlow::TRANSITION_OBSERVE')) > 0",
        label: 'Observation Tasks',
        description: 'Generate neutral evidence from prepared content. For media this starts with low-res observation plus optional high-res reads; for text it may be summaries or structural facts.',
    )]
    public const TRANSITION_OBSERVE = 'observe';

    #[Transition(
        from: self::PLACE_OBSERVED,
        to: self::PLACE_ANALYZED,
        async: true,
        transport: 'ai_subject.analyze',
        label: 'Analyze from Text',
        description: 'App-owned interpretation from observations and context. Does not inspect pixels or source content directly unless the app explicitly chooses that policy.',
        guard: "subject.pendingCount(constant('Survos\\\\AiWorkflowBundle\\\\Workflow\\\\SubjectFlow::TRANSITION_OBSERVE')) == 0",
    )]
    #[Transition(
        from: self::PLACE_ANALYZED,
        to: self::PLACE_ANALYZED,
        async: true,
        transport: 'ai_subject.analyze',
        label: 'Analyze from Text',
        description: 'App-owned interpretation from observations and context. Does not inspect pixels or source content directly unless the app explicitly chooses that policy.',
        guard: "subject.pendingCount('analyze') > 0",
    )]
    public const TRANSITION_ANALYZE = 'analyze';

    #[Transition(
        from: self::PLACE_ANALYZED,
        to: self::PLACE_REVIEWED,
        guard: "subject.pendingCount('analyze') == 0",
        async: true,
        transport: 'ai_subject.review',
        label: 'App-owned Review',
        description: 'App-owned approval point after analysis. The app decides whether this is automatic, human, or skipped by policy.',
    )]
    #[Transition(
        from: self::PLACE_REVIEWED,
        to: self::PLACE_REVIEWED,
        guard: "subject.pendingCount('analyze') == 0",
        async: true,
        transport: 'ai_subject.review',
        label: 'App-owned Review',
        description: 'App-owned approval point after analysis. The app decides whether this is automatic, human, or skipped by policy.',
    )]
    public const TRANSITION_REVIEW = 'review';

    #[Transition(
        from: self::PLACE_REVIEWED,
        to: self::PLACE_PUBLISHED,
        async: true,
        transport: 'ai_subject.publish',
        label: 'Publish',
        description: 'App-owned projection into denormalized fields, search indexes, exports, webhooks, or other final outputs.',
    )]
    #[Transition(
        from: self::PLACE_PUBLISHED,
        to: self::PLACE_PUBLISHED,
        async: true,
        transport: 'ai_subject.publish',
        label: 'Publish',
        description: 'App-owned projection into denormalized fields, search indexes, exports, webhooks, or other final outputs.',
    )]
    public const TRANSITION_PUBLISH = 'publish';
}
