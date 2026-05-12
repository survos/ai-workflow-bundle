<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

trait TaskNameTrait
{
    public function getTask(): string
    {
        return static::TASK;
    }
}
