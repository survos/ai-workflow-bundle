<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractAnalysisTask extends AbstractPromptTask implements AnalysisTaskInterface
{
    private ClaimRepository $claimRepository;

    #[Required]
    public function setClaimRepository(ClaimRepository $claimRepository): void
    {
        $this->claimRepository = $claimRepository;
    }

    protected function systemPromptTemplate(): string
    {
        return '@SurvosAiWorkflow/prompt/analysis/system.html.twig';
    }

    protected function userPromptTemplate(): ?string
    {
        $path = \dirname(__DIR__, 2) . "/templates/prompt/analysis/user/{$this->getTask()}.html.twig";

        return is_file($path)
            ? "@SurvosAiWorkflow/prompt/analysis/user/{$this->getTask()}.html.twig"
            : null;
    }

    protected function userPrompt(array $context): string
    {
        return 'Analyze the observation evidence and extract the requested information.';
    }

    protected function context(WorkflowSubjectInterface $subject): array
    {
        $context = parent::context($subject);

        // A consumed claim supplied directly by the subject (e.g. pasted text in the
        // app:tasks tester, or an already-hydrated entity) wins — this decouples the
        // dependency from its source and lets prompt preview work without a database.
        if (isset($context['observation_prose'])) {
            return $context;
        }

        $proseClaim = $this->claimRepository->findLatestByPredicate(
            subjectType: $subject::class,
            subjectId: $subject->getWorkflowSubjectId(),
            predicate: TaskClaimMapper::PRED_OBSERVATION_PROSE,
            scope: $subject->getWorkflowScope(),
        );

        if ($proseClaim !== null) {
            $context['observation_prose'] = (string) $proseClaim->value;
        }

        return $context;
    }

    protected function promptContext(array $inputs, array $context = []): array
    {
        return parent::promptContext($inputs, $context) + [
            'observationProse' => $context['observation_prose'] ?? null,
        ];
    }
}
