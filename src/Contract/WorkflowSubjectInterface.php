<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Contract;

use Survos\StateBundle\Traits\MarkingInterface;

interface WorkflowSubjectInterface extends MarkingInterface
{
    public function getWorkflowSubjectId(): string;

    /** Semantic subject-type key, e.g. 'fortepan' — used for claim storage. */
    public function getWorkflowSubjectType(): string;

    public function getWorkflowScope(): ?string;

    public function isWorkflowLocked(): bool;

    public function setWorkflowLocked(bool $locked): void;
}
