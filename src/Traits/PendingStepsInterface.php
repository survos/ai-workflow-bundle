<?php

namespace Survos\AiWorkflowBundle\Traits;

/**
 * Opt-in for entities driven by a multi-step AI workflow (see SubjectFlow).
 * Moved out of Survos\StateBundle\Traits\MarkingInterface, which every
 * marking-workflow entity implements whether or not it runs AI tasks.
 */
interface PendingStepsInterface
{
    /** Pending steps keyed by phase (transition name). */
    public array $pendingSteps { get; set; }

    public function addPendingStep(string $step, string $phase): static;

    public function shiftPendingStep(string $phase): ?string;

    /** Count steps for a phase — EL guard example: subject.pendingCount('observe') == 0 */
    public function pendingCount(string $phase): int;
}
