<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Result;

/**
 * Unified analysis output — one pass produces everything.
 *
 * Replaces DescriptionResult + MetadataResult + SummaryResult.
 *
 * confidence on each term/claim: "high" | "medium" | "low"
 * basis: short evidence string (what in the observation or provenance supports this)
 *
 * TaskClaimMapper reads: description, dense_summary, title, date_hint, date_confidence,
 * subjects ([{term,confidence,basis}]), people ([{term,basis}]), places ([{term,basis}]),
 * keywords_high/medium/low, language, organisations.
 */
final class MetadataResult implements \JsonSerializable
{
    public function __construct(
        /**
         * Catalogue description combining visual observation and provenance context.
         * 2–4 sentences. Written for a researcher, not a search engine.
         */
        public readonly ?string $description = null,

        /** Evidence basis for description — e.g. "provenance caption + observation prose" */
        public readonly ?string $descriptionBasis = null,

        /**
         * Dense retrieval summary ≤ 400 characters.
         * Entity-rich, factual, no filler. Optimised for RAG / Meilisearch /chat.
         * Who, what, when, where, material/technique. No "This image shows…" opener.
         */
        public readonly ?string $denseSummary = null,

        /** Evidence basis for dense summary */
        public readonly ?string $denseSummaryBasis = null,

        /**
         * Proposed title if none exists or the existing one is weak.
         * Omit if source already has a good title.
         */
        public readonly ?string $title = null,

        /** Evidence basis for title — e.g. "provenance caption" or "observation prose" */
        public readonly ?string $titleBasis = null,

        /**
         * Date or decade range. Format: "1943", "1930s", "1920–1935".
         */
        public readonly ?string $dateHint = null,

        /** Confidence in dateHint: 0–100 */
        public readonly ?int $dateConfidence = null,

        /** Evidence basis for date — e.g. "provenance date field" or "clothing style in observation" */
        public readonly ?string $dateBasis = null,

        /**
         * Subject headings with confidence (0–100) and evidence basis.
         * Each item: {term: string, confidence: int 0-100, basis: string}
         *
         * @var list<array{term: string, confidence?: int, basis?: string}>
         */
        public readonly array $subjects = [],

        /**
         * Named people — each: {term: string, confidence?: int 0-100, basis?: string}
         *
         * @var list<array{term: string, confidence?: int, basis?: string}>
         */
        public readonly array $people = [],

        /**
         * Geographic subjects — each: {term: string, confidence?: int 0-100, basis?: string}
         *
         * @var list<array{term: string, confidence?: int, basis?: string}>
         */
        public readonly array $places = [],

        /**
         * Organisations — each: {term: string, confidence?: int 0-100, basis?: string}
         *
         * @var list<array{term: string, confidence?: int, basis?: string}>
         */
        public readonly array $organisations = [],

        /** ISO 639-1 language of primary text content, if any. */
        public readonly ?string $language = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'description'       => $this->description,
            'description_basis' => $this->descriptionBasis,
            'dense_summary'     => $this->denseSummary,
            'dense_summary_basis' => $this->denseSummaryBasis,
            'title'             => $this->title,
            'title_basis'       => $this->titleBasis,
            'date_hint'         => $this->dateHint,
            'date_confidence'   => $this->dateConfidence,
            'date_basis'        => $this->dateBasis,
            'subjects'          => $this->subjects ?: null,
            'people'            => $this->people ?: null,
            'places'            => $this->places ?: null,
            'organisations'     => $this->organisations ?: null,
            'language'          => $this->language,
        ], static fn($v) => $v !== null && $v !== []);
    }
}
