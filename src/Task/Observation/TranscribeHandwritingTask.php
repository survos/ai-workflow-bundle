<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\AiWorkflowBundle\Contract\ImageSubjectInterface;
use Survos\AiWorkflowBundle\Contract\WorkflowSubjectInterface;
use Survos\AiWorkflowBundle\Result\OcrResult;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\ObservationTaskInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('HTR — faithful verbatim transcription of handwriting, preserving original language and spelling.', self::class)]
final class TranscribeHandwritingTask extends AbstractPromptTask implements ImageTaskInterface, ObservationTaskInterface
{
    public const string TASK = 'htr';

    public function __construct(
        #[Autowire(service: 'ai.agent.mistral_vision')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        return $subject instanceof ImageSubjectInterface && $subject->getWorkflowImageUrl() !== null;
    }

    protected function responseFormatClass(): string
    {
        return OcrResult::class;
    }

    protected function claimsFromData(array $data): array
    {
        return $this->claimMapper->map($data, Claim::PRED_HTR_TEXT);
    }
}
