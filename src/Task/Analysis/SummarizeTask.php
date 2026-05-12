<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Analysis;

use Survos\AiWorkflowBundle\Task\AbstractAnalysisTask;
use Survos\AiWorkflowBundle\Task\AsTask;


use Survos\AiWorkflowBundle\Result\SummaryResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Text-only summarisation of subject content and prior claims.', self::class)]
final class SummarizeTask extends AbstractAnalysisTask
{
    public const string TASK = 'summarize';

    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return SummaryResult::class;
    }
}
