<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Analysis;

use Survos\AiWorkflowBundle\Result\MetadataResult;
use Survos\AiWorkflowBundle\Task\AbstractAnalysisTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\DataContracts\Vocabulary\DcTerms;
use Survos\DataContracts\Vocabulary\ItemField;
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

    protected function promptContext(array $inputs, array $context = []): array
    {
        return parent::promptContext($inputs, $context) + [
            'contentType'           => $context[ItemField::CONTENT_TYPE] ?? $context['content_type'] ?? null,
            'provenanceDescription' => $context[DcTerms::DESCRIPTION->value] ?? $context['description'] ?? null,
            'existingTitle'         => $context[DcTerms::TITLE->value] ?? $context['title'] ?? null,
            'date'                  => $context[DcTerms::DATE->value] ?? $context['date'] ?? null,
            'country'               => $context[ItemField::COUNTRY] ?? null,
            'city'                  => $context[ItemField::CITY]    ?? null,
        ];
    }
}
