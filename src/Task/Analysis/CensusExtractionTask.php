<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Analysis;

use Survos\AiWorkflowBundle\Task\AbstractAnalysisTask;
use Survos\AiWorkflowBundle\Task\AsTask;


use Survos\AiWorkflowBundle\Contract\ContextSubjectInterface;
use Survos\AiWorkflowBundle\Contract\TextSubjectInterface;
use Survos\AiWorkflowBundle\Contract\WorkflowSubjectInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Text-only extraction of structured census form data from prior OCR output.', self::class)]
final class CensusExtractionTask extends AbstractAnalysisTask
{
    public const string TASK = 'extract_census';

    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        if ($subject instanceof TextSubjectInterface && trim((string) $subject->getWorkflowText()) !== '') {
            return true;
        }

        return $subject instanceof ContextSubjectInterface
            && trim((string) ($subject->getWorkflowContext()['ocr_text'] ?? $subject->getWorkflowContext()['ocrText'] ?? '')) !== '';
    }

    protected function inputs(WorkflowSubjectInterface $subject): array
    {
        $inputs = parent::inputs($subject);
        unset($inputs['image_url']);

        if (($inputs['text'] ?? '') === '' && $subject instanceof ContextSubjectInterface) {
            $context = $subject->getWorkflowContext();
            $inputs['text'] = $context['ocr_text'] ?? $context['ocrText'] ?? null;
        }

        return array_filter($inputs, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
