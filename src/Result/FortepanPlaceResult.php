<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Result;

/**
 * Structured output from the fortepan_place analysis task -- text-only, reads the observe
 * pass's prose/transcription for place clues (a station name, a shop sign, a postmark) rather
 * than looking at the image itself. See PLACE in the Fortepan IA curation criteria: "a city or
 * town, but also a point of interest, like a library, a bakery, a school."
 */
final class FortepanPlaceResult implements \JsonSerializable
{
    public function __construct(
        /** Best single place guess, as specific as the evidence supports -- a POI name, a town, or "town, state/country". Null if no textual clue exists at all. */
        public readonly ?string $place = null,

        /** high|medium|low -- how directly the evidence names/implies this place, not how interesting the guess is. */
        public readonly ?string $confidence = null,

        /** The specific textual clue this was inferred from (e.g. "postmark reads Ceremonial Station, OH" or "sign reads First National Bank of Millbrook"), so a human can verify it without re-reading the whole prose. */
        public readonly ?string $basis = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'place' => $this->place,
            'confidence' => $this->confidence,
            'basis' => $this->basis,
        ], static fn ($v) => $v !== null);
    }
}
