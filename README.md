# Survos AI Workflow Bundle

State-bundle workflow primitives for async AI work over any subject.

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

- `Workflow\SubjectFlow`: shared state-bundle definition.
- `Contract\WorkflowSubjectInterface`: minimal subject id plus queue/lock shape for entities. Claim subject type defaults to the PHP class name.
- `Task\TaskInterface`: callable workflow task that returns claims.
- `Task\TaskResult`: claims plus optional follow-up tasks.
- `Task\TaskRegistry`: lazy task lookup by name.
- `Task\TaskRunner`: consumes one queued task and records claims through `survos/ai-claims-bundle`.

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

`TaskRunner::runNext()` removes one task from the queue, records claims through `survos/ai-claims-bundle`, and appends any follow-up tasks returned by the task. For image workflows, `EnrichFromThumbnailTask::TASK` is usually the first observation task; it can append higher-resolution observation tasks such as `OcrMistralTask::TASK` or `TranscribeHandwritingTask::TASK`.

## Naming

Inside `Survos\AiWorkflowBundle`, class names intentionally avoid the `Ai` prefix where the namespace already carries it:

- `SubjectFlow`
- `TaskInterface`
- `TaskRegistry`
- `TaskRunner`

## Failure Model

There is no `failed` place in the initial workflow. Failures should be recorded on task runs, claims, or app-specific fields while the subject remains at its current milestone. Apps can add a failure place later if they need one.
