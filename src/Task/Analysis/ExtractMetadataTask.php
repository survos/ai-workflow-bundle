<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Analysis;

use Survos\AiWorkflowBundle\Task\AbstractAnalysisTask;
use Survos\AiWorkflowBundle\Task\AsTask;


use Survos\AiWorkflowBundle\Result\MetadataResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Text-only extraction of structured metadata fields from subject context and prior claims.', self::class)]
final class ExtractMetadataTask extends AbstractAnalysisTask
{
    public const string TASK = 'extract_metadata';

    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return MetadataResult::class;
    }
}
