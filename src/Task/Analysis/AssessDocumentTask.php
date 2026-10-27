<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Analysis;

use Survos\AiWorkflowBundle\Decision\DocumentAssessment;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Answer document questions from supplied evidence; never choose tasks.', self::class, consumes: ['dcterms:description'], produces: ['ai:handwrittenText', 'ai:typedText'])]
final class AssessDocumentTask extends AbstractPromptTask
{
    public const string TASK = 'assess_document';

    public function __construct(#[Autowire(service: 'ai.agent.assessment')] ?AgentInterface $agent = null)
    {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return DocumentAssessment::class;
    }

    protected function claimsFromData(array $data): array
    {
        $assessment = DocumentAssessment::fromArray($data);

        return $this->claimMapper->map(\array_filter(['handwritten_text' => $assessment->handwritten,
            'typed_text' => $assessment->printed], static fn (?bool $value): bool => null !== $value));
    }
}
