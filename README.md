# Survos AI Workflow Bundle

State-bundle workflow primitives for async AI work over any subject.

The bundle defines a small shared lifecycle:

```text
new -> prepared -> observed -> analyzed -> published
```

The important split is conceptual:

- `prepare`: make source content and context available.
- `observe`: create neutral evidence from the source. For images this is usually thumbnail observation plus optional high-resolution reads; for text it may be dense summaries, language, or structure.
- `analyze`: apply application meaning to observed evidence and context.
- `publish`: project the result into fields, indexes, exports, webhooks, or other app outputs.

The bundle does not decide what those transitions do. Applications provide workflow listeners, task policy, and publishing behavior.

The bundle may define reusable tasks and prompt templates. Treat those as a prompt/task repository, not app policy: applications still decide which tasks to queue, when to append follow-up tasks, and what `publish` means.

## Core Classes

- `Workflow\SubjectWF`: shared state-bundle definition.
- `Contract\WorkflowSubjectInterface`: minimal subject id plus queue/lock shape for entities. Claim subject type defaults to the PHP class name.
- `Task\TaskInterface`: callable workflow task that returns claims.
- `Task\TaskResult`: claims plus optional follow-up tasks.
- `Task\TaskRegistry`: lazy task lookup by name.
- `Task\TaskRunner`: consumes one queued task and records claims through `survos/ai-claims-bundle`.

Task outputs are not stored as opaque JSON result blobs. They are recorded as `ClaimRun` and `Claim` rows. Apps can still denormalize claim projections onto their own entities during `publish`.

## Naming

Inside `Survos\AiWorkflowBundle`, class names intentionally avoid the `Ai` prefix where the namespace already carries it:

- `SubjectWF`
- `TaskInterface`
- `TaskRegistry`
- `TaskRunner`

## Failure Model

There is no `failed` place in the initial workflow. Failures should be recorded on task runs, claims, or app-specific fields while the subject remains at its current milestone. Apps can add a failure place later if they need one.
