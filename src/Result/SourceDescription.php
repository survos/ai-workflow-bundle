<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Result;

final readonly class SourceDescription implements \JsonSerializable
{
    public function __construct(public string $description)
    {
    }

    public function jsonSerialize(): array
    {
        return ['description' => $this->description];
    }
}
