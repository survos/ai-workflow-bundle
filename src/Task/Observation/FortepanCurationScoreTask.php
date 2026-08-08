<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\AiWorkflowBundle\Result\FortepanCurationScoreResult;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\ClaimsBundle\Service\RawClaim;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fortepan IA's own curation criteria (survos-sites/ssai, Tac 2026-08-07), scored per photo:
 * ACTION, CULTURAL_PRACTICE, HISTORICAL_SIGNIFICANCE, CAPTIVATES, IN_THE_ACT, plus
 * quality/originality. Image-based by design (Tac, same conversation) -- these all require
 * actually looking at the photo (composition, expression, condition, "is someone visibly
 * taking a photo"), unlike PLACE, which is usually just reading text observe already
 * transcribed -- see FortepanPlaceTask, a separate text-only task, for that one.
 *
 * A standalone vision call, not a shared one with observe/observe_hires -- interpretation
 * (curator judgment) is deliberately kept out of ObserveTask's "evidence, not interpretation"
 * contract (see ObserveResult's own docblock). Queued as a normal analysis_tasks follow-up
 * from observe/observe_hires like any other analysis step -- it just happens to need the
 * image, which is why it lives under Observation/ rather than Analysis/ (directory reflects
 * ImageTaskInterface vs AnalysisTaskInterface, not evidence-vs-interpretation).
 */
#[AsTask('Fortepan IA curation scoring (action, cultural practice, historical significance, captivates, in-the-act, quality, originality) -- requires the image.', self::class)]
final class FortepanCurationScoreTask extends AbstractPromptTask implements ImageTaskInterface
{
    public const string TASK = 'fortepan_curation_score';

    public function __construct(
        #[Autowire(service: 'ai.agent.description')]
        AgentInterface $agent,
    ) {
        parent::__construct($agent);
    }

    protected function responseFormatClass(): string
    {
        return FortepanCurationScoreResult::class;
    }

    protected function claimsFromData(array $data): array
    {
        $claims = [];

        foreach ([
            'actionScore' => 'fortepan:actionScore',
            'culturalPracticeScore' => 'fortepan:culturalPracticeScore',
            'historicalSignificanceScore' => 'fortepan:historicalSignificanceScore',
            'captivatesScore' => 'fortepan:captivatesScore',
            'inTheActScore' => 'fortepan:inTheActScore',
            'qualityScore' => 'fortepan:qualityScore',
            'originalityScore' => 'fortepan:originalityScore',
        ] as $key => $predicate) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $claims[] = new RawClaim($predicate, (int) $data[$key], basis: $data['rationale'] ?? null);
            }
        }

        return $claims;
    }
}
