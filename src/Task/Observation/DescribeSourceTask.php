<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\AiWorkflowBundle\Result\SourceDescription;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Describe visible source evidence without choosing downstream tasks.', self::class, produces: ['dcterms:description'])]
final class DescribeSourceTask extends AbstractPromptTask implements ImageTaskInterface
{
    public const string TASK = 'describe_source';

    public function __construct(#[Autowire(service: 'ai.agent.description')] ?AgentInterface $agent = null)
    {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return SourceDescription::class;
    }

    protected function claimsFromData(array $data): array
    {
        return $this->claimMapper->map($data);
    }
}
