<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\AiWorkflowBundle\Result\EnrichFromThumbnailResult;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\ObservationTaskInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('(Deprecated) Quick image classification pass. Use observe instead.', self::class)]
final class TriageTask extends AbstractPromptTask implements ImageTaskInterface, ObservationTaskInterface
{
    public const string TASK = 'triage';

    public function __construct(
        #[Autowire(service: 'ai.agent.description')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return EnrichFromThumbnailResult::class;
    }

    protected function inputs(WorkflowSubjectInterface $subject): array
    {
        $inputs = parent::inputs($subject);
        if ($subject instanceof ContextSubjectInterface) {
            $context  = $subject->getWorkflowContext();
            $imageUrl = $context['thumbnail_file_url'] ?? $context['local_file_url'] ?? null;
            if (is_string($imageUrl) && $imageUrl !== '') {
                $inputs['image_url'] = $imageUrl;
            }
        }

        return $inputs;
    }

    protected function followUpTasks(array $data): array
    {
        if (($data['pixels_done'] ?? true) !== false) {
            return [];
        }

        return match ($data['high_res_goal'] ?? null) {
            'read_handwriting'               => ['htr'],
            'extract_form_fields'            => ['ocr_mistral', 'extract_census'],
            'read_dense_print', 'layout_or_table' => ['ocr_mistral'],
            default                          => [],
        };
    }
}
