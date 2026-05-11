<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\DependencyInjection\Compiler;

use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class TaskRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $disabled = (array) $container->getParameter('survos_ai_workflow.disabled_tasks');
        $map = [];
        $locatorRefs = [];

        foreach ($container->findTaggedServiceIds('ai_workflow.task') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;

            if (!class_exists($class)) {
                continue;
            }

            $taskName = $this->resolveTaskName($class, $tags);
            if ($taskName === null || in_array($taskName, $disabled, true)) {
                continue;
            }

            $definition->setPublic(false);
            $map[$taskName] = $serviceId;
            $locatorRefs[$taskName] = new Reference($serviceId);
        }

        $container->setParameter('survos_ai_workflow.task_map', $map);

        if ($container->hasDefinition(TaskRegistry::class)) {
            $container->getDefinition(TaskRegistry::class)
                ->setArgument('$tasks', ServiceLocatorTagPass::register($container, $locatorRefs))
                ->setArgument('$taskMap', $map);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $tags
     */
    private function resolveTaskName(string $class, array $tags): ?string
    {
        foreach ($tags as $attributes) {
            if (isset($attributes['task'])) {
                return (string) $attributes['task'];
            }
        }

        try {
            $reflection = new \ReflectionClass($class);
            /** @var object{getTask: callable(): string} $instance */
            $instance = $reflection->newInstanceWithoutConstructor();

            return $instance->getTask();
        } catch (\Throwable) {
            return null;
        }
    }
}
