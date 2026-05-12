# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer install
composer test           # PHPUnit
php vendor/bin/phpunit  # directly
```

Console commands available once the bundle is installed in an app:

```bash
bin/console ai:workflow:run <task> <entity> <id>          # run a single task, print claims
bin/console ai:workflow:run <task> <entity> <id> --no-persist  # dry run
bin/console ai:workflow:run <task> <entity> <id> --pretty      # dump raw AI response
bin/console ai:workflow:run <task> <entity> <id> --operator '{"content_type":"postcard"}'
```

## Architecture

### Lifecycle

Subjects move through a single shared state machine (`SubjectFlow`, `ai_subject` workflow):

```
new → prepared → observed → analyzed → reviewed → published
```

The `observe` and `analyze` transitions are re-entrant (a subject can repeat them). The `analyze` transition is guarded by `subject.pendingCount == 0` — the queue must be empty before moving past `observed`.

### How tasks run

1. An app workflow listener (using `#[AsTransitionListener]`) populates `$subject->pendingSteps` with task name strings during `prepare`.
2. On each `observe` transition, the listener calls `TaskRunner::runNext($subject)`.
3. `TaskRunner` shifts the first task off `pendingSteps`, resolves it via `TaskRegistry`, calls `task->run($subject)`, and records all output through `ClaimIngestor` from `survos/ai-claims-bundle`.
4. If `TaskResult::$appendTasks` is non-empty, those names are appended to `pendingSteps`, driving further `observe` transitions.

Task outputs are never stored as opaque JSON blobs — they are always `ClaimRun` + `Claim` rows.

### Key classes

| Class | Role |
|---|---|
| `SubjectFlow` | State machine definition (places, transitions, constants) |
| `WorkflowSubjectInterface` | Minimal entity contract: `getWorkflowSubjectId()`, `getWorkflowScope()`, lock flag, `pendingSteps` |
| `AbstractPromptTask` | Base for all built-in tasks: renders Twig prompt templates, calls `symfony/ai-agent`, normalises response, maps via `TaskClaimMapper` |
| `TaskClaimMapper` | Converts AI response arrays to typed `RawClaim` lists using Dublin Core and `ai:*` predicates |
| `TaskRegistry` | Lazy service-locator keyed by task name; populated by `TaskRegistryPass` compiler pass |
| `TaskRunner` | Consumes one step from the queue, records claims, appends follow-ups |
| `AsTask` | Class attribute that provides description metadata; task name defaults to snake-cased class short name minus `Task` suffix, or `TASK` constant |

### Subject interfaces

Entities implement the capabilities they expose:

- `WorkflowSubjectInterface` — required by all subjects
- `ImageSubjectInterface` — `getWorkflowImageUrl()`
- `TextSubjectInterface` — `getWorkflowText()`
- `ContextSubjectInterface` — `getWorkflowContext()` (returns associative array: html, mime, metadata, description, title, …)

`AbstractPromptTask::inputs()` inspects these interfaces to populate the Twig template context.

### Adding a task

1. Create `src/Task/MyNewTask.php` extending `AbstractPromptTask`.
2. Define `public const string TASK = 'my_new_task';` — this is also the template directory slug.
3. Inject the desired agent via `#[Autowire(service: 'ai.agent.<name>')]`.
4. Optionally override `responseFormatClass()` to return a structured result DTO.
5. Create `templates/prompt/my_new_task/system.html.twig` and `user.html.twig`.

The task is automatically discovered via the `ai_workflow.task` tag — no manual wiring needed. `TaskRegistryPass` builds the service-locator and parameter maps at compile time.

### Prompt templates

All prompts live under `templates/prompt/{task-slug}/`. They receive:

```twig
{{ imageUrl }}, {{ text }}, {{ html }}, {{ mime }},
{{ context }}, {{ metadata }}, {{ ocr_text }},
{{ title }}, {{ description }}, {{ type }}, {{ prior }}
```

Templates are namespaced as `@SurvosAiWorkflow/prompt/{slug}/system.html.twig`.

### Claim predicates

`TaskClaimMapper` maps AI response keys to standard predicates:

- `dcterms:title`, `dcterms:description`, `dcterms:abstract`, `dcterms:subject`, `dcterms:spatial`, `dcterms:date`, `dcterms:type`, `dcterms:language`
- `ai:observationProse`, `ai:transcription`, `ai:nextAction`, `ai:taskSkipped`, `ai:taskFailed`
- Boolean flags: `has_text`, `typed_text`, `handwritten_text`, `is_form`, `is_filled_form`

Confidence strings `"high"` / `"medium"` / `"low"` in AI responses translate to `1.0` / `0.7` / `0.45`.

### Bundle wiring

All DI setup is in `SurvosAiWorkflowBundle.php`:

- `configure()` — `disabled_tasks` config key
- `loadExtension()` — registers `TaskRegistry`, `TaskRunner`, `TaskClaimMapper`, `TaskController`, optional menu subscriber; auto-loads all `*Task.php` files except the abstract base
- `build()` — registers `TaskInterface` autoconfiguration tag, adds `TaskRegistryPass`
- `prependExtension()` — adds `__DIR__/Workflow` to `survos_state` workflow paths, registers templates under `SurvosAiWorkflow` namespace
