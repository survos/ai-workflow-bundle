<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\AiClaimsBundle\Entity\Claim;
use Survos\AiClaimsBundle\Service\RawClaim;

final class TaskClaimMapper
{
    public const PRED_OBSERVATION_PROSE = 'ai:observationProse';
    public const PRED_TRANSCRIPTION     = 'ai:transcription';
    public const PRED_NEXT_ACTION       = 'ai:nextAction';

    public const PRED_TITLE = 'dcterms:title';
    public const PRED_DESCRIPTION = 'dcterms:description';
    public const PRED_ABSTRACT = 'dcterms:abstract';
    public const PRED_SUBJECT = 'dcterms:subject';
    public const PRED_SPATIAL = 'dcterms:spatial';
    public const PRED_DATE = 'dcterms:date';
    public const PRED_TYPE = 'dcterms:type';
    public const PRED_LANGUAGE = 'dcterms:language';

    /**
     * @param array<string, mixed> $data
     * @return list<RawClaim>
     */
    public function map(array $data, ?string $textPredicate = null): array
    {
        $claims = [];
        $textPredicate ??= Claim::PRED_OCR_TEXT;

        $this->appendScalar($claims, self::PRED_TITLE, $data['title'] ?? null, $this->confidence($data['title_confidence'] ?? $data['confidence'] ?? null));
        $this->appendScalar($claims, self::PRED_DESCRIPTION, $data['description'] ?? null);
        $this->appendScalar($claims, self::PRED_ABSTRACT, $data['summary'] ?? null);
        $this->appendScalar($claims, Claim::PRED_DENSE_SUMMARY, $data['dense_summary'] ?? null);
        $this->appendScalar($claims, $textPredicate, $data['text'] ?? null, $this->confidence($data['confidence'] ?? null));
        $this->appendScalar($claims, $textPredicate, $data['annotated_text'] ?? null);
        $this->appendScalar($claims, self::PRED_LANGUAGE, $data['language'] ?? null);
        $this->appendScalar($claims, self::PRED_DATE, $data['date_hint'] ?? $data['dateRange'] ?? null, $this->confidence($data['date_confidence'] ?? null));
        $this->appendScalar($claims, self::PRED_TYPE, $data['content_type'] ?? null, $this->confidence($data['content_type_confidence'] ?? null));
        $this->appendScalar($claims, Claim::PRED_PIXELS_DONE, $data['pixels_done'] ?? null, basis: $data['pixel_decision_reason'] ?? null);
        $this->appendScalar($claims, Claim::PRED_HIGH_RES_GOAL, $data['high_res_goal'] ?? null, basis: $data['risk_if_skipped'] ?? null);

        foreach (['has_text' => Claim::PRED_HAS_TEXT, 'typed_text' => Claim::PRED_TYPED_TEXT, 'handwritten_text' => Claim::PRED_HANDWRITTEN_TEXT, 'is_form' => Claim::PRED_IS_FORM, 'is_filled_form' => Claim::PRED_IS_FILLED_FORM] as $key => $predicate) {
            if (array_key_exists($key, $data)) {
                $this->appendScalar($claims, $predicate, (bool) $data[$key]);
            }
        }

        $this->appendList($claims, Claim::PRED_PERSON, $data['people'] ?? []);
        $this->appendList($claims, self::PRED_SPATIAL, $data['places'] ?? []);
        $this->appendList($claims, self::PRED_SUBJECT, $data['subjects'] ?? []);
        $this->appendList($claims, self::PRED_SUBJECT, $data['keywords'] ?? []);
        $this->appendList($claims, self::PRED_SUBJECT, $data['keywords_high'] ?? [], 1.0);
        $this->appendList($claims, self::PRED_SUBJECT, $data['keywords_medium'] ?? [], 0.7);
        $this->appendList($claims, self::PRED_SUBJECT, $data['keywords_low'] ?? [], 0.45);
        $this->appendList($claims, Claim::PRED_HIGH_RES_TARGET, $data['high_res_targets'] ?? []);
        $this->appendList($claims, 'ai:organisation', $data['organisations'] ?? []);
        $this->appendStructuredList($claims, Claim::PRED_SPECULATION, $data['speculations'] ?? []);
        $this->appendStructuredList($claims, 'ai:layoutBlock', $data['layout_blocks'] ?? []);
        $this->appendStructuredList($claims, 'ai:imageBlock', $data['image_blocks'] ?? []);
        $this->appendStructuredList($claims, 'ai:ocrPage', $data['pages'] ?? []);
        $this->appendStructuredList($claims, 'ai:table', $data['tables'] ?? []);

        return $claims;
    }

    /**
     * @param list<RawClaim> $claims
     */
    private function appendScalar(array &$claims, string $predicate, mixed $value, ?float $confidence = null, ?string $basis = null): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        $claims[] = new RawClaim($predicate, $value, $confidence ?? 1.0, $basis);
    }

    /**
     * @param list<RawClaim> $claims
     * @param iterable<mixed> $values
     */
    private function appendList(array &$claims, string $predicate, iterable $values, ?float $confidence = null): void
    {
        foreach ($values as $value) {
            $basis = null;
            $itemConfidence = $confidence;
            if (is_array($value)) {
                $basis = isset($value['basis']) ? (string) $value['basis'] : null;
                $itemConfidence ??= $this->confidence($value['confidence'] ?? null);
                $value = $value['term'] ?? $value['name'] ?? $value['value'] ?? null;
            }
            $this->appendScalar($claims, $predicate, $value, $itemConfidence, $basis);
        }
    }

    /**
     * @param list<RawClaim> $claims
     * @param iterable<mixed> $values
     */
    private function appendStructuredList(array &$claims, string $predicate, iterable $values): void
    {
        foreach ($values as $value) {
            $confidence = is_array($value) ? $this->confidence($value['confidence'] ?? null) : null;
            $basis = is_array($value) && isset($value['basis']) ? (string) $value['basis'] : null;
            $this->appendScalar($claims, $predicate, $value, $confidence, $basis);
        }
    }

    private function confidence(mixed $value): ?float
    {
        return match ($value) {
            'high' => 1.0,
            'medium' => 0.7,
            'low' => 0.45,
            default => is_numeric($value) ? (float) $value : null,
        };
    }
}
