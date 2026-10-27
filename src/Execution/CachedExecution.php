<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Execution;

/** @template T of object */
final readonly class CachedExecution
{
    /** @param T $value */
    public function __construct(
        public object $value,
        public string $key,
        public bool $cacheHit,
        public int $elapsedMs,
        public int $originalDurationMs,
        public string $createdAt,
    ) {
    }
}
