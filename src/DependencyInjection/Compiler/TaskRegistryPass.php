<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\DependencyInjection\Compiler;

use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class TaskRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $disabled    = (array) $container->getParameter('survos_ai_workflow.disabled_tasks');
        $taskMap     = [];   // name => serviceId
        $taskMeta    = [];   // name => AsTask::toArray()
        $locatorRefs = [];

        foreach ($container->findTaggedServiceIds('ai_workflow.task') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $class      = $definition->getClass() ?? $serviceId;

            if (!class_exists($class)) {
                continue;
            }

            $name        = $this->resolveName($class, $tags);
            $description = $this->resolveDescription($class);

            if ($name === null || in_array($name, $disabled, true)) {
                continue;
            }

            $attribute  = $this->resolveAttribute($class);
            $descriptor = new AsTask(
                $description,
                $class,
                $attribute?->consumes ?? [],
                $attribute?->produces ?? [],
                $attribute?->samples ?? [],
            );

            $definition->setPublic(false);
            $taskMap[$name]     = $serviceId;
            $taskMeta[$name]    = $descriptor->toArray();
            $locatorRefs[$name] = new Reference($serviceId);
        }

        $container->setParameter('survos_ai_workflow.task_map',  $taskMap);
        $container->setParameter('survos_ai_workflow.task_meta', $taskMeta);

        if ($container->hasDefinition(TaskRegistry::class)) {
            $container->getDefinition(TaskRegistry::class)
                ->setArgument('$tasks',    ServiceLocatorTagPass::register($container, $locatorRefs))
                ->setArgument('$taskMap',  $taskMap)
                ->setArgument('$taskMeta', $taskMeta);
        }
    }

    private function resolveName(string $class, array $tags): ?string
    {
        foreach ($tags as $attributes) {
            if (isset($attributes['task'])) {
                return (string) $attributes['task'];
            }
        }

        if (defined($class . '::TASK')) {
            return constant($class . '::TASK');
        }

        try {
            return AsTask::deriveName((new \ReflectionClass($class))->getShortName());
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveAttribute(string $class): ?AsTask
    {
        try {
            foreach ((new \ReflectionClass($class))->getAttributes(AsTask::class) as $attr) {
                return $attr->newInstance();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function resolveDescription(string $class): string
    {
        try {
            foreach ((new \ReflectionClass($class))->getAttributes(AsTask::class) as $attr) {
                return $attr->newInstance()->description;
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
