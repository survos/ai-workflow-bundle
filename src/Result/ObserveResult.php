<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Result;

/**
 * Shape of the observation envelope returned by ObserveTask.
 *
 * Three sections, always in this order:
 *   routing      — machine-consumed routing block (content type, text flags, next action)
 *   prose        — neutral free-text description; no controlled vocabulary, no catalog fields
 *   transcription — typed JSON when structured text is reliably legible; null otherwise
 *
 * This is evidence, not interpretation. Analysis (keywords, subjects, summaries)
 * belongs in the analyze phase and reads these claims as its source material.
 */
final class ObserveResult
{
    public function __construct(
        public readonly array   $routing,
        public readonly string  $prose,
        public readonly ?array  $transcription = null,
    ) {}
}
