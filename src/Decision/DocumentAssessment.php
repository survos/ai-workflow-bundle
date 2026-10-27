<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Decision;

/** Shared answer contract. Null means the available evidence cannot answer the question. */
final readonly class DocumentAssessment implements \JsonSerializable
{
    public const REVISION = 'document-assessment-v1';

    public function __construct(
        public ?bool $handwritten,
        public ?bool $printed,
        public ?bool $correspondence,
        public string $basis,
    ) {
        if ('' === \trim($basis)) {
            throw new \InvalidArgumentException('An assessment requires its evidence basis.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['handwritten' => $this->handwritten, 'printed' => $this->printed,
            'correspondence' => $this->correspondence, 'basis' => $this->basis];
    }

    /** Strict input boundary for saved answers, source rules and human submissions. */
    public static function fromArray(array $data): self
    {
        foreach (['handwritten', 'printed', 'correspondence'] as $field) {
            if (!\array_key_exists($field, $data) || (null !== $data[$field] && !\is_bool($data[$field]))) {
                throw new \InvalidArgumentException('Assessment answers must be boolean or null.');
            }
        }
        if (!\is_string($data['basis'] ?? null)) {
            throw new \InvalidArgumentException('Assessment basis must be text.');
        }

        return new self($data['handwritten'], $data['printed'], $data['correspondence'], $data['basis']);
    }
}
