<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Result;

/**
 * Structured output from the fortepan_curation_score task -- Fortepan IA's own curation
 * criteria (survos-sites/ssai, Tac 2026-08-07), scored per photo. Deliberately image-based
 * (unlike fortepan_place): quality/originality/composition/"is someone in the act of taking a
 * photo" all require actually looking at the picture, not just reading a prior description of
 * it -- this is the split Tac asked for over PLACE, which can come from text alone.
 *
 * Each score is 0-100, not a boolean -- "images that captivate" is explicitly a spectrum
 * ("magic ... a personally touching detail ... a special balance between dark and light"),
 * not a yes/no, and forcing a threshold here would throw away exactly the gradient a curator
 * wants to sort/filter by later.
 *
 * PLACE is deliberately NOT scored here -- it's fortepan_place's job (text-only, reads
 * observe's prose/transcription for a named location) so a good place guess doesn't require
 * re-deriving it from the image a second time, and the two never disagree with each other
 * silently.
 */
final class FortepanCurationScoreResult implements \JsonSerializable
{
    public function __construct(
        /** Tells a story -- funny, tragic, a situation people recognize (a rally, a particular kind of motorbike, a moment mid-happening). */
        public readonly int $actionScore = 0,

        /** Communicates a specific cultural practice -- Fortepan wants breadth across different practices, not just "does a practice exist here". */
        public readonly int $culturalPracticeScore = 0,

        /** A recognizable historical moment, or the distinct value of an amateur photographer's unique vantage point on one. */
        public readonly int $historicalSignificanceScore = 0,

        /** The catch-all: a personally touching detail, accidental framing, a striking balance of dark and light, a particular expression, the pull between two objects in frame, a thing or person isolated in a compelling way -- ordinary photo elevated into something with magic, often unintentionally so. */
        public readonly int $captivatesScore = 0,

        /** Someone visibly in the act of taking a photograph -- a specific, separately-called-out Fortepan favorite, not folded into actionScore. */
        public readonly int $inTheActScore = 0,

        /** Technical/physical condition and clarity -- focus, exposure, damage, legibility. Distinct from captivatesScore: a technically rough photo can still captivate, and a technically clean one can be inert. */
        public readonly int $qualityScore = 0,

        /** How unusual or distinctive this image is relative to typical archive submissions -- not a duplicate/near-duplicate of a common shot type. */
        public readonly int $originalityScore = 0,

        /** One or two sentences a curator can actually use: which score(s) this justifies and why, not a restatement of the visual description observe already produced. */
        public readonly ?string $rationale = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'actionScore' => $this->actionScore,
            'culturalPracticeScore' => $this->culturalPracticeScore,
            'historicalSignificanceScore' => $this->historicalSignificanceScore,
            'captivatesScore' => $this->captivatesScore,
            'inTheActScore' => $this->inTheActScore,
            'qualityScore' => $this->qualityScore,
            'originalityScore' => $this->originalityScore,
            'rationale' => $this->rationale,
        ], static fn ($v) => $v !== null);
    }
}
