<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle;

use Survos\AiWorkflowBundle\DependencyInjection\Compiler\TaskRegistryPass;
use Survos\AiWorkflowBundle\Controller\TaskController;
use Survos\AiWorkflowBundle\Menu\AiWorkflowMenuSubscriber;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Task\TaskRunner;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class SurvosAiWorkflowBundle extends AbstractBundle
{
    public function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/Controller/', 'attribute');
    }

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
            ->set('survos_ai_workflow.task_map',  [])
            ->set('survos_ai_workflow.task_meta', []);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TaskRegistry::class)
            ->public()
            ->arg('$taskMap', '%survos_ai_workflow.task_map%');

        $services->set(TaskClaimMapper::class);
        $services->set(TaskRunner::class)
            ->public();

        $services->set(TaskController::class)
            ->public()
            ->tag('controller.service_arguments');

        if (class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            $services->set(AiWorkflowMenuSubscriber::class);
        }

        $services->load('Survos\\AiWorkflowBundle\\Command\\', __DIR__ . '/Command/');
        $services->load('Survos\\AiWorkflowBundle\\Task\\Observation\\', __DIR__ . '/Task/Observation/');
        $services->load('Survos\\AiWorkflowBundle\\Task\\Analysis\\', __DIR__ . '/Task/Analysis/');
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(TaskInterface::class)
            ->addTag('ai_workflow.task');

        $container->addCompilerPass(new TaskRegistryPass());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('survos_state', [
            'workflow_paths' => [
                '%kernel.project_dir%/src/Workflow',
                __DIR__ . '/Workflow',
            ],
        ]);

        $builder->prependExtensionConfig('twig', [
            'paths' => [
                \dirname(__DIR__) . '/templates' => 'SurvosAiWorkflow',
            ],
        ]);
    }
}
