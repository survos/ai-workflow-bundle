<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\AiWorkflowBundle\Repository\SubjectRepository;
use Survos\DataContracts\Dto\Item\BaseItemDto;
use Survos\DataContracts\Dto\Item\GenericItemDto;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\AiWorkflowBundle\Traits\PendingStepsInterface;
use Survos\AiWorkflowBundle\Traits\PendingStepsTrait;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Uid\Ulid;

/**
 * Tracks workflow state for any subject that is not itself a Doctrine entity —
 * Pixie rows, JSONL records, external API objects, etc.
 *
 * Claims live in the claim table keyed by the same (scope, subjectType, subjectId).
 */
#[ORM\Entity(repositoryClass: SubjectRepository::class)]
#[ORM\Table(name: 'subject')]
#[ORM\UniqueConstraint(name: 'uniq_subject', fields: ['scope', 'subjectType', 'subjectId'])]
#[EntityMeta(icon: 'mdi:dots-circle', group: 'AI Workflow', label: 'Subjects')]
#[RouteIdentity(field: 'id')]
class Subject implements WorkflowSubjectInterface, MarkingInterface, PendingStepsInterface, ImageSubjectInterface, ContextSubjectInterface
{
    use MarkingTrait;
    use PendingStepsTrait;
    use RouteIdentityTrait;

    #[ORM\Id]
    #[ORM\Column(length: 26)]
    public string $id;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $scope = null;

    /** Dataset name, entity class short name, or any stable type discriminator. */
    #[ORM\Column(length: 255)]
    public string $subjectType;

    /** Record identifier within the dataset — ARK, ULID, integer id, etc. */
    #[ORM\Column(length: 255)]
    public string $subjectId;

    /**
     * The source record — JSONL row, Pixie row, API response, etc.
     * Keys used by the AI pipeline:
     *   image_url      → getWorkflowImageUrl()
     *   thumbnail_url  → getWorkflowImageUrl() fallback
     *   (all keys)     → getWorkflowContext()
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $data = [];

    #[ORM\Column]
    public bool $workflowLocked = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdAt = null;

    public function __construct(string $subjectType, string $subjectId, ?string $scope = null)
    {
        $this->id          = (string) new Ulid();
        $this->subjectType = $subjectType;
        $this->subjectId   = $subjectId;
        $this->scope       = $scope;
        $this->createdAt   = new \DateTimeImmutable();
    }

    // ── WorkflowSubjectInterface ──────────────────────────────────────────────

    public function getWorkflowSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getWorkflowSubjectType(): string
    {
        return $this->subjectType;
    }

    /** FQCN used as the claim subjectType — the original source entity class or dataset key. */
    public function getWorkflowSubjectClass(): string
    {
        return self::class;
    }

    public function getWorkflowScope(): ?string
    {
        return $this->scope;
    }

    public function isWorkflowLocked(): bool
    {
        return $this->workflowLocked;
    }

    public function setWorkflowLocked(bool $locked): void
    {
        $this->workflowLocked = $locked;
    }

    // ── ImageSubjectInterface ─────────────────────────────────────────────────

    /** Transient — set by workflow listener before a task run, cleared after. Not persisted. */
    public ?string $resolvedImageUrl = null;

    private ?BaseItemDto $dto = null;

    public function dto(): BaseItemDto
    {
        return $this->dto ??= GenericItemDto::fromNormalized($this->data ?? []);
    }

    public function getWorkflowImageUrl(): ?string
    {
        return $this->resolvedImageUrl
            ?? $this->dto()->iiifBase
            ?? null;
    }

    // ── ContextSubjectInterface ───────────────────────────────────────────────

    public function getWorkflowContext(): array
    {
        return $this->data;
    }
}
