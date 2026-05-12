# AGENTS.md – Guidelines for Agentic Coding Assistants

This document defines **mandatory rules** for any automated or semi-automated coding agent (including LLM-based tools such as opencode) that modifies this repository.

The overriding goals are:

- **Deterministic, reviewable diffs**
- **Zero protocol surprises**
- **Explicit, boring PHP code**
- **No "magic" or suppressed errors**

If an agent cannot follow these rules, it should refuse to proceed.

---

## Project Overview

**Survos AiWorkflowBundle** is a Symfony 8.0 / PHP 8.4 bundle providing:

- A task pipeline for AI-driven content enrichment (OCR, description, metadata, title, summary, observation, etc.)
- A `TaskRegistry` + `TaskRunner` for dispatching tasks against `WorkflowSubjectInterface` entities
- `AbstractPromptTask` as the base for all tasks — renders Twig prompt templates, calls `symfony/ai-agent`, and maps results to claims
- Integration with `survos/state-bundle` for Symfony Workflow-based state transitions
- Integration with `survos/ai-claims-bundle` for structured claim storage

The bundle is used in content-enrichment pipelines where **correctness and reproducibility matter**.

---

## Supported Environment

- **PHP**: 8.4+
- **Symfony**: ^8.0
- **Composer**: 2.x
- **Testing**: PHPUnit 13+

---

## CRITICAL: Agent Output Requirements

### 1. Output Format (Non-Negotiable)

Agents **MUST emit plain text output only**.

- ❌ Do NOT emit structured response items
- ❌ Do NOT emit `reasoning`, `analysis`, or multi-item protocols
- ✅ Emit deterministic, reviewable text suitable for patch/diff workflows

---

### 2. Change Scope and Granularity

- Prefer **small, surgical diffs**
- Do not reformat unrelated code
- Do not introduce opportunistic refactors
- If a full-file rewrite is required, state it explicitly

---

## PHP Coding Standards

### Required in Every PHP File

```php
declare(strict_types=1);
```

### Classes and Types

- Use `final class` unless extension is explicitly required
- Use `readonly` for immutable value objects
- Type all parameters, returns, and properties
- Avoid `mixed` unless absolutely necessary

---

## Global Function Usage (IMPORTANT)

When using PHP built-in functions inside a namespace, **import them explicitly**:

```php
use function json_encode;
use function json_decode;
use function sprintf;
use function trim;
use function array_filter;
use function is_array;
use function is_string;
use function str_starts_with;
use function str_ends_with;
use function parse_url;
use function file_get_contents;
```

### Forbidden Patterns

- ❌ Leading backslashes on global functions (`\json_encode()`, `\trim()`, etc.)
- ❌ The `@` error suppression operator

All failures must be handled **explicitly**.

---

## Error Handling Rules

- Never suppress warnings or notices
- If a failure matters, throw an exception
- Fail fast on programmer errors
- Prefer `\RuntimeException` or `\InvalidArgumentException`

---

## Bundle Structure

```
src/
├── Command/              # Symfony console commands
├── Contract/             # Subject interfaces (ImageSubjectInterface, etc.)
├── Controller/           # TaskController
├── DependencyInjection/  # Compiler pass (TaskRegistryPass)
├── Menu/                 # Optional menu subscriber
├── Result/               # Typed result DTOs (OcrResult, TitleResult, etc.)
├── Task/                 # Task interfaces, abstract base, concrete tasks, registry, runner
├── Workflow/             # SubjectFlow Symfony Workflow definition
└── SurvosAiWorkflowBundle.php

templates/prompt/
└── {task-slug}/
    ├── system.html.twig
    └── user.html.twig
```

---

## Task Rules

### Adding a New Task

1. Create `src/Task/MyNewTask.php` extending `AbstractPromptTask`.
2. Define a `TASK` constant that matches the template directory slug.
3. Optionally override `responseFormatClass()` to return a structured result DTO class-string.
4. Create `templates/prompt/{task-slug}/system.html.twig` and `user.html.twig`.
5. The task is auto-registered via the `ai_workflow.task` tag — no manual wiring needed.

### Task Class Shape

```php
<?php
declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\AI\Agent\AgentInterface;

final class MyNewTask extends AbstractPromptTask
{
    public const string TASK = 'my-new-task';

    public function __construct(
        #[Autowire(service: 'ai.agent.default')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }
}
```

### Forbidden Task Patterns

- ❌ Do not call `$this->agent->call()` directly outside of `AbstractPromptTask::run()`
- ❌ Do not render prompts outside of `AbstractPromptTask`
- ❌ Do not bypass `TaskClaimMapper` when producing claims

---

## Subject Interface Rules

All entities passed to tasks must implement `WorkflowSubjectInterface`. Additionally:

- Implement `ImageSubjectInterface` if the subject has an image URL
- Implement `TextSubjectInterface` if the subject has plain text
- Implement `ContextSubjectInterface` if the subject has a context array (html, mime, metadata, etc.)

---

## Symfony Console Command Rules (MANDATORY)

These rules apply to **all Symfony console commands** in this repository.

### 1. Use `__invoke()` (Always)

- Commands **must** implement `__invoke()`
- Do **not** implement `execute()`
- Do **not** override `configure()`

### 2. Do NOT Extend `Command` Unless Necessary

Prefer **plain invokable classes** with the `#[AsCommand]` attribute. Import `Command::SUCCESS` statically if needed.

### 3. Use Attributes for Arguments and Options

```php
public function __invoke(
    SymfonyStyle $io,
    #[Argument('Subject identifier')]
    string $id,
    #[Option('Limit records')]
    ?int $limit = null,
): int {
```

### 4. `AsCommand` Attribute Rules

```php
#[AsCommand('ai-workflow:run-task', 'Run a named task against a subject')]
```

- ❌ Never use named `description:` argument
- ✅ Description is the second positional argument

### 5. Reserved Parameters — NEVER USE

Do not define parameters named `$verbose`, `$version`, or `$help`.

### 6. Always Inject `SymfonyStyle` First

`SymfonyStyle $io` must be the **first parameter** of `__invoke()`.

### 7. Always Return a Status Code

```php
return Command::SUCCESS;
```

---

## Testing Guidelines

- Tests must be deterministic
- Use temporary directories with random names; clean up in teardown
- Prefer real AI agent fakes/stubs over live API calls in unit tests
- Use `#[Test]` and `#[CoversClass]` attributes

---

## Security Considerations

- Validate URLs before fetching remote images
- Do not leak absolute paths in error messages
- Do not trust external API responses blindly — always normalize

---

## Agent Expectations Summary

Agents working on this repository **MUST**:

- Emit plain text only
- Follow PHP 8.4 / Symfony 8.0 idioms
- Use `use function` imports for built-ins
- Never use `@` error suppression
- Make minimal, reviewable changes
- Extend `AbstractPromptTask` for new AI tasks
- Register task templates under `templates/prompt/{task-slug}/`

If a requested change would violate these rules, the agent must **ask before proceeding**.

---

## Recommended Agent Configuration

For best results with patch-based workflows:

- **Response format**: text only
- **Reasoning output**: disabled
- **Streaming items**: disabled
