<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Contract;

interface TextSubjectInterface
{
    public function getWorkflowText(): ?string;
}
