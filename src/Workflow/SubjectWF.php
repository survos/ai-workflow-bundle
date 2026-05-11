<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Workflow;

use Survos\AiWorkflowBundle\Contract\WorkflowSubjectInterface;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

/**
 * Shared AI lifecycle definition.
 *
 * Places are durable milestones. Apps store in-progress task queues on the
 * subject, then use guards/listeners to decide when the next transition is
 * allowed. Business policy belongs in app listeners.
 */
#[Workflow(supports: [WorkflowSubjectInterface::class], name: self::WORKFLOW_NAME)]
final class SubjectWF
{
    public const WORKFLOW_NAME = 'ai_subject';

    #[Place(
        initial: true,
        info: 'Subject registered',
        description: 'The subject exists but has not been prepared for AI work.',
        bgColor: 'secondary',
        next: [self::TRANSITION_PREPARE],
    )]
    public const PLACE_NEW = 'new';

    #[Place(
        info: 'Prepared for AI work',
        description: 'Source content, metadata, URLs, or normalized text are available for observation.',
        bgColor: 'primary',
        next: [self::TRANSITION_OBSERVE],
    )]
    public const PLACE_PREPARED = 'prepared';

    #[Place(
        info: 'Observed evidence exists',
        description: 'Observation has produced neutral evidence. Additional observation tasks may still be queued on the subject.',
        bgColor: 'primary',
        next: [self::TRANSITION_OBSERVE, self::TRANSITION_ANALYZE],
    )]
    public const PLACE_OBSERVED = 'observed';

    #[Place(
        info: 'Analysis complete',
        description: 'Application-level interpretation has been derived from observed evidence and context.',
        bgColor: 'success',
        next: [self::TRANSITION_ANALYZE, self::TRANSITION_PUBLISH],
    )]
    public const PLACE_ANALYZED = 'analyzed';

    #[Place(
        info: 'Published',
        description: 'Analysis has been projected into app fields, indexes, exports, webhooks, or other public outputs.',
        bgColor: 'success',
    )]
    public const PLACE_PUBLISHED = 'published';

    #[Transition(
        from: [self::PLACE_NEW],
        to: self::PLACE_PREPARED,
        async: true,
        info: 'Prepare',
        description: 'App-owned setup: fetch source, normalize content, create media variants, resolve URLs, or load metadata.',
    )]
    public const TRANSITION_PREPARE = 'prepare';

    #[Transition(
        from: [self::PLACE_PREPARED, self::PLACE_OBSERVED],
        to: self::PLACE_OBSERVED,
        async: true,
        info: 'Observe',
        description: 'Generate neutral evidence from prepared content. For media this is usually low-res observation plus optional high-res reads; for text it may be summaries or structural facts.',
    )]
    public const TRANSITION_OBSERVE = 'observe';

    #[Transition(
        from: [self::PLACE_OBSERVED, self::PLACE_ANALYZED],
        to: self::PLACE_ANALYZED,
        async: true,
        info: 'Analyze',
        description: 'App-owned interpretation from observations and context. Does not inspect pixels or source content directly unless the app explicitly chooses that policy.',
    )]
    public const TRANSITION_ANALYZE = 'analyze';

    #[Transition(
        from: [self::PLACE_ANALYZED, self::PLACE_PUBLISHED],
        to: self::PLACE_PUBLISHED,
        async: true,
        info: 'Publish',
        description: 'App-owned projection into denormalized fields, search indexes, exports, webhooks, or other final outputs.',
    )]
    public const TRANSITION_PUBLISH = 'publish';
}
