<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\AiWorkflowBundle\Result\EnrichFromThumbnailResult;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\ObservationTaskInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('(Deprecated) Combined observe+analyze pass. Use observe then analyze instead.', self::class)]
final class EnrichFromThumbnailTask extends AbstractPromptTask implements ImageTaskInterface, ObservationTaskInterface
{
    public const string TASK = 'enrich_from_thumbnail';

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

    protected function followUpTasks(array $data): array
    {
        if (($data['pixels_done'] ?? true) !== false) {
            return [];
        }

        return match ($data['high_res_goal'] ?? null) {
            'read_handwriting'    => ['htr'],
            'extract_form_fields' => ['ocr_mistral', 'extract_census'],
            'read_dense_print'    => ['ocr_mistral'],
            default               => [],
        };
    }
}
