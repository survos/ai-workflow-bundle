<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Contract;

interface ImageSubjectInterface
{
    public function getWorkflowImageUrl(): ?string;
}
