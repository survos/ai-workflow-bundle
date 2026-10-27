<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Decision;

/** Keep answer provenance separate from the model's response schema. */
final readonly class AssessmentEvidence
{
    public function __construct(public DocumentAssessment $assessment, public string $origin, public string $reference)
    {
        if (!\in_array($origin, ['human', 'source', 'tool'], true) || '' === \trim($reference)) {
            throw new \InvalidArgumentException('Known evidence requires an origin and reference.');
        }
    }
}
