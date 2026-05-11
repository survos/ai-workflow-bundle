<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class TaskRegistry
{
    /**
     * @param array<string,string> $taskMap task name => service id
     */
    public function __construct(
        private readonly ServiceProviderInterface $tasks,
        #[Autowire('%survos_ai_workflow.task_map%')]
        private readonly array $taskMap = [],
    ) {
    }

    public function has(string $taskName): bool
    {
        return isset($this->taskMap[$taskName]) && $this->tasks->has($taskName);
    }

    public function get(string $taskName): ?TaskInterface
    {
        if (!$this->has($taskName)) {
            return null;
        }

        $task = $this->tasks->get($taskName);
        \assert($task instanceof TaskInterface);

        return $task;
    }

    /**
     * @return array<string,string>
     */
    public function getTaskMap(): array
    {
        return $this->taskMap;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function allMeta(): array
    {
        $meta = [];
        foreach (array_keys($this->taskMap) as $taskName) {
            $meta[$taskName] = $this->get($taskName)?->getMeta() ?? [];
        }

        return $meta;
    }
}
