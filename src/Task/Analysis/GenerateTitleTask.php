<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Analysis;

use Survos\AiWorkflowBundle\Task\AbstractAnalysisTask;
use Survos\AiWorkflowBundle\Task\AsTask;


use Survos\AiWorkflowBundle\Result\TitleResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Text-only generation of a concise title from subject context and prior claims.', self::class, consumes: ['ai:observationProse'], produces: ['dcterms:title'])]
final class GenerateTitleTask extends AbstractAnalysisTask
{
    public const string TASK = 'generate_title';

    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return TitleResult::class;
    }
}
