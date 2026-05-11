<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\AiWorkflowBundle\Contract\WorkflowSubjectInterface;

/**
 * A callable task inside an app-owned workflow.
 *
 * Tasks return claims and optional follow-up task names. They do not persist
 * opaque result blobs.
 */
interface TaskInterface
{
    public function getTask(): string;

    public function run(WorkflowSubjectInterface $subject): TaskResult;

    public function supports(WorkflowSubjectInterface $subject): bool;

    /**
     * @return array<string,mixed>
     */
    public function getMeta(): array;
}
