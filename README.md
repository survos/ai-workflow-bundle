# Survos AI Workflow Bundle

Symfony 8.2 workflow primitives for async AI work over any subject.

The bundle defines a small shared lifecycle:

```text
new -> prepared -> observed -> analyzed -> reviewed -> published
```

The important split is conceptual:

- `prepare`: make source content and context available.
- `observe`: create neutral evidence from the source. For images this is usually thumbnail observation plus optional high-resolution reads; for text it may be dense summaries, language, or structure.
- `analyze`: apply application meaning to observed evidence and context.
- `review`: app-defined approval after analysis. This can be automatic policy, human review, or another app-specific gate.
- `publish`: project the result into fields, indexes, exports, webhooks, or other app outputs.

The bundle does not decide what those transitions do. Applications provide workflow listeners, task policy, and publishing behavior.

The bundle may define reusable tasks and prompt templates. Treat those as a prompt/task repository, not app policy: applications still decide which tasks to queue, when to append follow-up tasks, and what `publish` means.

## Core Classes

- `Workflow\SubjectFlow`: native attribute-based workflow definition.
- `Contract\WorkflowSubjectInterface`: minimal subject id plus queue/lock shape for entities. Claim subject type defaults to the PHP class name.
- `Task\TaskInterface`: callable workflow task that returns claims.
- `Task\TaskResult`: claims plus optional follow-up tasks.
- `Task\TaskRegistry`: lazy task lookup by name.
- `Task\TaskRunner`: consumes one queued task and records claims through `survos/claims-bundle`.

Task outputs are not stored as opaque JSON result blobs. They are recorded as `ClaimRun` and `Claim` rows. Apps can still denormalize claim projections onto their own entities during `publish`.

## Queueing Tasks

Applications queue and run tasks from workflow listeners. Use task constants instead of string literals so built-in task names stay refactorable:

```php
<?php

namespace App\Workflow;

use App\Entity\GalleryImage;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Task\EnrichFromThumbnailTask;
use Survos\AiWorkflowBundle\Task\TaskRunner;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

final class GalleryWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskRunner $taskRunner,
    ) {
    }

    #[AsTransitionListener(SubjectFlow::WORKFLOW_NAME, SubjectFlow::TRANSITION_PREPARE)]
    public function onPrepare(TransitionEvent $event): void
    {
        $image = $this->getImage($event);

        if ($image->getWorkflowQueue() === []) {
            $image->setWorkflowQueue([EnrichFromThumbnailTask::TASK]);
        }

        $this->entityManager->flush();
    }

    #[AsTransitionListener(SubjectFlow::WORKFLOW_NAME, SubjectFlow::TRANSITION_OBSERVE)]
    public function onObserve(TransitionEvent $event): void
    {
        $image = $this->getImage($event);

        $this->taskRunner->runNext($image);
        $this->entityManager->flush();
    }

    private function getImage(TransitionEvent $event): GalleryImage
    {
        $image = $event->getSubject();
        \assert($image instanceof GalleryImage);

        return $image;
    }
}
```

`TaskRunner::runNext()` removes one task from the queue, records claims through `survos/claims-bundle`, and appends any follow-up tasks returned by the task. For image workflows, `EnrichFromThumbnailTask::TASK` is usually the first observation task; it can append higher-resolution observation tasks such as `OcrMistralTask::TASK` or `TranscribeHandwritingTask::TASK`.

## Naming

Inside `Survos\AiWorkflowBundle`, class names intentionally avoid the `Ai` prefix where the namespace already carries it:

- `SubjectFlow`
- `TaskInterface`
- `TaskRegistry`
- `TaskRunner`

## Failure Model

There is no `failed` place in the initial workflow. Failures should be recorded on task runs, claims, or app-specific fields while the subject remains at its current milestone. Apps can add a failure place later if they need one.

## Workflow integration

Enable `survos/workflow-extras-bundle` and `survos/workflow-messenger-bundle`,
with `workflow: ~` in your application configuration. State-bundle is no longer
required. The AI workflow supports subjects implementing `PendingStepsInterface`;
other workflow subjects, such as media records, retain their own lifecycle.
Doctrine subjects keep a scalar `marking` property and the usual Symfony marking
store accessors. Existing persisted place values are unchanged.

Configure Messenger transports named `ai_subject.prepare`, `ai_subject.observe`,
`ai_subject.analyze`, `ai_subject.review`, and `ai_subject.publish`. Transition
metadata selects these transports explicitly. The application's transport DSNs
control how the jobs are delivered; the bundle does not create queues implicitly.

Observation repeats while observation tasks remain. Analysis begins after
observation drains and repeats only while analysis tasks remain. Review waits
for analysis to drain; automatic continuation then publishes once. Applications
can add guard listeners for human approval or other review policy. Manual review
and publish transitions remain repeatable. Pending tasks retain FIFO ordering.

Menu integration uses `MenuSlot::ADMIN_NAVBAR_MENU` through `AsMenuListener`.

### Typed document assessments and cached operations

`DocumentAssessment` is a shared answer contract for source rules, humans, and AI:
`handwritten`, `printed`, and `correspondence` are nullable booleans, with a required
`basis`. Unknown is distinct from false. `AssessmentEvidence` attaches a non-AI
origin (`source`, `human`, or `tool`) and provenance reference outside the model schema.

The `describe_source` task extracts visual evidence without choosing follow-up tasks.
`assess_document` uses the application's `ai.agent.assessment`, requests
`response_format: DocumentAssessment::class`, and returns its hydrated DTO in
`TaskResult::output`. It needs supplied text evidence. Applications supply the
workflow policy, model configuration, persistence, and any trusted source rules.

`AbstractPromptTask` now consumes class-based outputs through `asObject($class)`.
Only tasks without a response class use the existing text/JSON fallback. Existing
claim mappers still receive arrays, converted from the hydrated DTO without text
parsing. `outputClass()` exposes the output contract for schema-aware cache keys.

`OperationCache::execute($identity, $operation)` stores successful object results for
24 hours by default. The caller must include all relevant inputs, prompt/schema
versions, model/provider options, content fingerprints, and scope in `$identity`.
Exceptions are not cached. The returned `CachedExecution` separates cache-hit status
and current elapsed time from the original result's creation time and duration.
The service uses `cache.app`; a consumer can override its cache argument.

## Idea: OpenRouter as an optional platform (not adopted)

[OpenRouter](https://openrouter.ai/) is one OpenAI-compatible endpoint in front of hundreds of models, with
fallbacks, per-token pass-through pricing and cross-provider cost reporting. `symfony/ai-open-router-platform`
(v1.0.0-BETA1) is a ready-made `symfony/ai` bridge, so using it is cheaper than writing our own multi-model router.

Worth trying for the **text analysis tasks** (`SummarizeTask`, `GenerateTitleTask`, `ExtractMetadataTask`, ...):
register it as another `ai.platform.*` and A/B models per task with config only. Keep recording the resolved model
in `RunMeta` so claims stay reproducible.

Not a fit for:

- `OcrMistralTask` and `PeriodicalStructureTask` — they pin a model and call Mistral's dedicated endpoints directly.
- `BatchableTaskInterface` — batching relies on provider-native batch APIs (`mistral`, `openai`, `anthropic`).
- Bulk corpus work, where a gateway adds a hop and possibly a fee.
- Image AI, which goes through mediary.

Before relying on it, check from the API docs: the fee on top of provider rates, structured-output and tool-calling
support per model, and data-retention terms. Only the homepage was reviewed (2026-10-27).
