<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class TaskRegistry
{
    /** @var array<string, AsTask> */
    private readonly array $descriptors;

    /**
     * @param array<string,string>              $taskMap  name => serviceId
     * @param array<string,array<string,mixed>> $taskMeta name => AsTask::toArray()
     */
    public function __construct(
        private readonly ServiceProviderInterface $tasks,
        #[Autowire('%survos_ai_workflow.task_map%')]
        private readonly array $taskMap = [],
        #[Autowire('%survos_ai_workflow.task_meta%')]
        array $taskMeta = [],
    ) {
        $this->descriptors = array_map(
            static fn (array $data) => AsTask::fromArray($data),
            $taskMeta,
        );
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

    public function getDescriptor(string $taskName): ?AsTask
    {
        return $this->descriptors[$taskName] ?? null;
    }

    /** @return array<string, TaskInterface> */
    public function getByInterface(string $interface, array $exclude = []): array
    {
        $result = [];
        foreach (array_keys($this->taskMap) as $name) {
            if (in_array($name, $exclude, true)) {
                continue;
            }
            $task = $this->get($name);
            if ($task instanceof $interface) {
                $result[$name] = $task;
            }
        }

        return $result;
    }

    /** @return array<string,string> */
    public function getTaskMap(): array
    {
        return $this->taskMap;
    }

    /** @return array<string,array<string,mixed>> */
    public function allMeta(): array
    {
        $meta = [];
        foreach (array_keys($this->taskMap) as $taskName) {
            $meta[$taskName] = $this->get($taskName)?->getMeta() ?? [];
        }

        return $meta;
    }
}
