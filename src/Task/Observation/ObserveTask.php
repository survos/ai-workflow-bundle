<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\ClaimsBundle\Service\RawClaim;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AnalysisTaskInterface;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\ObservationTaskInterface;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\Attribute\Required;

#[AsTask('Low-resolution visual observation. Produces a routing decision, prose description, and structured transcription. Queues follow-up image and analysis tasks.', self::class)]
final class ObserveTask extends AbstractPromptTask implements ImageTaskInterface, ObservationTaskInterface
{
    public const string TASK = 'observe';

    private TaskRegistry $taskRegistry;

    public function __construct(
        #[Autowire(service: 'ai.agent.description')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    #[Required]
    public function setTaskRegistry(TaskRegistry $taskRegistry): void
    {
        $this->taskRegistry = $taskRegistry;
    }

    protected function responseFormatClass(): ?string
    {
        return null;
    }

    protected function promptContext(array $inputs, array $context = []): array
    {
        $base = parent::promptContext($inputs, $context);

        $operatorHint = array_filter([
            'content_type' => $context['content_type'] ?? $context['type'] ?? null,
            'voice_note'   => $context['voice_note']   ?? null,
            'collection'   => $context['collection']   ?? null,
        ]);

        $existingMetadata = array_filter([
            'title'      => $context['title']      ?? null,
            'date'       => $context['date']        ?? null,
            'creator'    => $context['creator']     ?? null,
            'collection' => $context['collection']  ?? null,
        ]);

        $availableSteps = array_keys(
            $this->taskRegistry->getByInterface(ImageTaskInterface::class, [self::TASK]),
        );

        $availableAnalysis = array_keys(
            $this->taskRegistry->getByInterface(AnalysisTaskInterface::class),
        );

        return $base + [
            'operatorHint'      => $operatorHint      ?: null,
            'existingMetadata'  => $existingMetadata  ?: null,
            'availableSteps'    => $availableSteps,
            'availableAnalysis' => $availableAnalysis,
        ];
    }

    protected function claimsFromData(array $data): array
    {
        $routing       = $data['routing']       ?? [];
        $prose         = $data['prose']         ?? '';
        $transcription = $data['transcription'] ?? null;

        $flat = array_merge(
            [
                'content_type'            => $routing['content_type']            ?? null,
                'content_type_confidence' => $routing['content_type_confidence'] ?? null,
                'content_type_basis'      => $routing['content_type_basis']      ?? null,
                'risk_if_skipped'         => $routing['risk_if_skipped']         ?? null,
            ],
            $routing['text_flags'] ?? [],
        );

        $claims = $this->claimMapper->map($flat);

        if ($prose !== '') {
            $claims[] = new RawClaim(TaskClaimMapper::PRED_OBSERVATION_PROSE, $prose, 100, null);
        }
        if ($transcription !== null) {
            $claims[] = new RawClaim(TaskClaimMapper::PRED_TRANSCRIPTION, $transcription, 100, null);
        }

        return $claims;
    }

    protected function followUpTasks(array $data): array
    {
        return array_values(array_filter(
            (array) ($data['routing']['pending_steps'] ?? []),
            fn (string $step) => $this->taskRegistry->has($step),
        ));
    }

    protected function followUpAnalysisTasks(array $data): array
    {
        return array_values(array_filter(
            (array) ($data['routing']['analysis_tasks'] ?? []),
            fn (string $step) => $this->taskRegistry->has($step),
        ));
    }
}
