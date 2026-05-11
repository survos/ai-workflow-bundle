<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Contract;

/**
 * Minimal queue shape for entities using SubjectWF.
 *
 * Apps may back these methods with existing fields such as aiQueue and aiLocked.
 * Task outputs should be recorded as claims, not duplicated as result blobs.
 */
interface WorkflowSubjectInterface
{
    public function getWorkflowSubjectId(): string;

    public function getWorkflowScope(): ?string;

    /**
     * @return list<string>
     */
    public function getWorkflowQueue(): array;

    /**
     * @param list<string> $queue
     */
    public function setWorkflowQueue(array $queue): void;

    public function isWorkflowLocked(): bool;

    public function setWorkflowLocked(bool $locked): void;
}
