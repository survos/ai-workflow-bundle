<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\AiClaimsBundle\Service\RawClaim;
use Survos\AiClaimsBundle\Service\RunMeta;

final readonly class TaskResult
{
    /**
     * @param list<RawClaim> $claims
     * @param list<string>   $appendTasks          follow-up observation/image tasks
     * @param list<string>   $appendAnalysisTasks  analysis tasks to queue once image tasks drain
     */
    public function __construct(
        public array $claims = [],
        public array $appendTasks = [],
        public array $appendAnalysisTasks = [],
        public ?RunMeta $meta = null,
    ) {
    }
}
