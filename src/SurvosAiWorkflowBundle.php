<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle;

use Survos\AiWorkflowBundle\DependencyInjection\Compiler\TaskRegistryPass;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Task\TaskRunner;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosAiWorkflowBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('disabled_tasks')
                    ->info('Registered task names to remove from the task registry.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('survos_ai_workflow.disabled_tasks', $config['disabled_tasks'])
            ->set('survos_ai_workflow.task_map', []);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TaskRegistry::class)
            ->public()
            ->arg('$taskMap', '%survos_ai_workflow.task_map%');

        $services->set(TaskRunner::class)
            ->public();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(TaskInterface::class)
            ->addTag('ai_workflow.task');

        $container->addCompilerPass(new TaskRegistryPass());
    }
}
