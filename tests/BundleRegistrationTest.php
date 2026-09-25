<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\SurvosAiWorkflowBundle;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

use function basename;
use function dirname;
use function glob;

final class BundleRegistrationTest extends TestCase
{
    public function testCommandsAndTaskRegistryRemainRegistered(): void
    {
        $builder = new ContainerBuilder();
        (new SurvosAiWorkflowBundle())->loadExtension(['disabled_tasks' => []], $this->configurator($builder), $builder);

        self::assertTrue($builder->hasDefinition(TaskRegistry::class));
        foreach (glob(dirname(__DIR__).'/src/Command/*.php') as $file) {
            self::assertTrue($builder->hasDefinition('Survos\\AiWorkflowBundle\\Command\\'.basename($file, '.php')));
        }
    }

    public function testDoctrineAndTwigNamesRemainCompatible(): void
    {
        $builder = new ContainerBuilder();
        (new SurvosAiWorkflowBundle())->prependExtension($this->configurator($builder), $builder);

        $mapping = $builder->getExtensionConfig('doctrine')[0]['orm']['mappings']['SurvosAiWorkflowBundle'];
        self::assertSame('AiWorkflow', $mapping['alias']);
        self::assertSame('Survos\\AiWorkflowBundle\\Entity', $mapping['prefix']);
        self::assertSame('SurvosAiWorkflow', $builder->getExtensionConfig('twig')[0]['paths'][dirname(__DIR__).'/templates']);
    }

    private function configurator(ContainerBuilder $builder): ContainerConfigurator
    {
        $instanceof = [];

        return new ContainerConfigurator($builder, new PhpFileLoader($builder, new FileLocator()), $instanceof, __DIR__, __FILE__);
    }
}
