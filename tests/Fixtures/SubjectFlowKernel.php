<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Tests\Fixtures;

use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Workflow\WorkflowBundle;

final class SubjectFlowKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $directory)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new WorkflowBundle();
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'workflow-test', 'test' => true]);
        $container->extension('workflow', []);
        $container->extension('security', ['firewalls' => ['main' => ['security' => false]]]);
        $container->services()->set(SubjectFlow::class)->autoconfigure();
        $container->services()->alias('test.workflow', 'state_machine.ai_subject')->public();
    }
}
