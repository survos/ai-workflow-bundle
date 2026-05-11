<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\AiClaimsBundle\Service\RawClaim;
use Survos\AiClaimsBundle\Service\RunMeta;

/**
 * Result of one workflow task.
 *
 * Claims are the primary persisted output. Follow-up tasks are appended to the
 * subject queue by TaskRunner, allowing the first observe/analyze task to route
 * the rest of the work without adding workflow places.
 */
final readonly class TaskResult
{
    /**
     * @param list<RawClaim> $claims
     * @param list<string> $appendTasks
     */
    public function __construct(
        public array $claims = [],
        public array $appendTasks = [],
        public ?RunMeta $meta = null,
    ) {
    }
}
