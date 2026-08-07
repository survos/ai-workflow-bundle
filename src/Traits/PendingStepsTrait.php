<?php

namespace Survos\AiWorkflowBundle\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opt-in for entities driven by a multi-step AI workflow (see SubjectFlow).
 * Moved out of Survos\StateBundle\Traits\MarkingTrait, which every
 * marking-workflow entity uses whether or not it runs AI tasks.
 */
trait PendingStepsTrait
{
    /** Keyed by phase (transition name): ['observe' => ['task_a'], 'analyze' => ['task_b']] */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $pendingSteps = [];

    public function addPendingStep(string $step, string $phase): static
    {
        if (!in_array($step, $this->pendingSteps[$phase] ?? [], true)) {
            $this->pendingSteps[$phase][] = $step;
        }
        return $this;
    }

    public function shiftPendingStep(string $phase): ?string
    {
        if (empty($this->pendingSteps[$phase])) {
            return null;
        }
        $step = array_shift($this->pendingSteps[$phase]);
        if ($this->pendingSteps[$phase] === []) {
            unset($this->pendingSteps[$phase]);
        }
        return $step;
    }

    public function pendingCount(string $phase): int
    {
        return count($this->pendingSteps[$phase] ?? []);
    }

    /** Flat list of all pending steps across all phases, for display. */
    public function getAllPendingSteps(): array
    {
        $all = [];
        foreach ($this->pendingSteps as $value) {
            if (is_array($value)) {
                array_push($all, ...$value);
            }
        }
        return $all;
    }
}
