<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Analysis;

use Survos\AiWorkflowBundle\Result\FortepanPlaceResult;
use Survos\AiWorkflowBundle\Task\AbstractAnalysisTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * PLACE, from the Fortepan IA curation criteria (survos-sites/ssai, Tac 2026-08-07): "a city or
 * town, but also a point of interest, like a library, a bakery, a school." Text-only by design
 * (Tac, same conversation) -- a named place usually comes from something legible in the image
 * (a station sign, a shop name, a postmark) that observe's prose/transcription already
 * captured, not from re-examining the picture itself. See FortepanCurationScoreTask for the
 * criteria that do need the image.
 */
#[AsTask('Text-only place inference (city/town/POI) from observation evidence.', self::class)]
final class FortepanPlaceTask extends AbstractAnalysisTask
{
    public const string TASK = 'fortepan_place';

    /** AbstractAnalysisTask's own $claimRepository is private, not protected -- a second
     *  autowired reference to the same shared service, not a new instance. */
    private ClaimRepository $claimRepository;

    #[Required]
    public function setPlaceClaimRepository(ClaimRepository $claimRepository): void
    {
        $this->claimRepository = $claimRepository;
    }

    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return FortepanPlaceResult::class;
    }

    /** Pull transcription too, not just prose -- a postmark/sign reading is exactly the clue this task looks for, and AbstractAnalysisTask's base context() only fetches PRED_OBSERVATION_PROSE. */
    protected function context(WorkflowSubjectInterface $subject): array
    {
        $context = parent::context($subject);

        if (isset($context['transcription'])) {
            return $context;
        }

        $transcriptionClaim = $this->claimRepository->findLatestByPredicate(
            subjectType: $subject::class,
            subjectId: $subject->getWorkflowSubjectId(),
            predicate: TaskClaimMapper::PRED_TRANSCRIPTION,
            scope: $subject->getWorkflowScope(),
        );

        if ($transcriptionClaim !== null) {
            $context['transcription'] = $transcriptionClaim->value;
        }

        return $context;
    }

    protected function promptContext(array $inputs, array $context = []): array
    {
        return parent::promptContext($inputs, $context) + [
            'transcription' => $context['transcription'] ?? null,
        ];
    }

    protected function claimsFromData(array $data): array
    {
        $place = $data['place'] ?? null;
        if (!is_string($place) || trim($place) === '') {
            return [];
        }

        return [new RawClaim(
            TaskClaimMapper::PRED_SPATIAL,
            trim($place),
            $this->confidenceScore($data['confidence'] ?? null),
            $data['basis'] ?? null,
        )];
    }

    private function confidenceScore(?string $level): ?int
    {
        return match ($level) {
            'high' => 90,
            'medium' => 60,
            'low' => 30,
            default => null,
        };
    }
}
