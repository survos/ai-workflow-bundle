<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Contract;

interface ContextSubjectInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getWorkflowContext(): array;
}
