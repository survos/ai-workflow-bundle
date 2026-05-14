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

#[AsTask('High-resolution visual observation. Produces exhaustive inventory-level prose and structured transcription for images where the thumbnail was insufficient.', self::class)]
final class ObserveHiresTask extends AbstractPromptTask implements ImageTaskInterface, ObservationTaskInterface
{
    public const string TASK = 'observe_hires';

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

        $availableAnalysis = array_keys(
            $this->taskRegistry->getByInterface(AnalysisTaskInterface::class),
        );

        return $base + [
            'availableAnalysis' => $availableAnalysis,
            'stage1Routing'     => $context['stage1_routing'] ?? null,
        ];
    }

    protected function claimsFromData(array $data): array
    {
        $prose         = $data['prose']         ?? '';
        $transcription = $data['transcription'] ?? null;

        $claims = [];

        if ($prose !== '') {
            $claims[] = new RawClaim(TaskClaimMapper::PRED_OBSERVATION_PROSE, $prose, 100, null);
        }
        if ($transcription !== null) {
            $claims[] = new RawClaim(TaskClaimMapper::PRED_TRANSCRIPTION, $transcription, 100, null);
        }

        return $claims;
    }

    protected function followUpAnalysisTasks(array $data): array
    {
        return array_values(array_filter(
            (array) ($data['analysis_tasks'] ?? []),
            fn (string $step) => $this->taskRegistry->has($step),
        ));
    }
}
