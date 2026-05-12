<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Analysis;

use Survos\AiWorkflowBundle\Task\AbstractAnalysisTask;
use Survos\AiWorkflowBundle\Task\AsTask;


use Survos\AiWorkflowBundle\Result\DescriptionResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Generates a prose description from subject context without opening the image.', self::class)]
final class ContextDescriptionTask extends AbstractAnalysisTask
{
    public const string TASK = 'context_description';

    public function __construct(
        #[Autowire(service: 'ai.agent.description')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return DescriptionResult::class;
    }
}
