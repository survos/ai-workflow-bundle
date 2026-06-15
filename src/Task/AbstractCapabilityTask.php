<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\TextSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\ClaimsBundle\Service\RunMeta;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * A task whose work is a SINGLE platform capability call — `$platform->invoke($model, $input)` —
 * not an agent loop.
 *
 * This is the "transducer" half of the engine: a modality goes in (image, PDF, audio, text),
 * a structured result comes out, and that result is mapped to claims. There is no prompt, no
 * tool calling, no memory and no multi-turn conversation — the model name is pinned and the
 * input is a typed Message\Content DTO. Contrast with {@see AbstractPromptTask}, which drives
 * an `ai.agent.*` (prompt templates, tools, memory) for the "reasoning" tasks.
 *
 * Deciding between the two is the capability-vs-agent question: if you would not give the model
 * a tool or a conversation, it is a capability — extend this. OCR, speech-to-text and embeddings
 * all belong here.
 *
 * @author Tac Tacelosky <tacman@gmail.com>
 */
abstract class AbstractCapabilityTask implements TaskInterface
{
    protected TaskClaimMapper $claimMapper;

    public function __construct(
        protected readonly PlatformInterface $platform,
    ) {
    }

    #[Required]
    public function setCapabilityServices(TaskClaimMapper $claimMapper): void
    {
        $this->claimMapper = $claimMapper;
    }

    /**
     * The pinned model name to invoke, e.g. 'mistral-ocr-latest'.
     */
    abstract protected function model(): string;

    /**
     * Build the invoke input from the subject — typically a Message\Content DTO
     * (DocumentUrl, ImageUrl, Audio) or a plain string.
     *
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $context
     */
    abstract protected function buildInput(WorkflowSubjectInterface $subject, array $inputs, array $context): array|string|object;

    /**
     * Map the deferred platform result into the flat data array the claim mapper understands.
     * Subclasses pick how to read it: $result->asText(), ->asVectors(), ->getResult(), or
     * ->getRawResult()->getData() for the raw provider payload.
     *
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    abstract protected function mapResult(DeferredResult $result, array $inputs, array $context): array;

    /**
     * Options forwarded to invoke() — e.g. response_format or a provider annotation schema.
     *
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    protected function invokeOptions(array $inputs, array $context): array
    {
        return [];
    }

    /**
     * Text predicate for claim mapping; null falls back to the OCR-text default.
     */
    protected function claimPredicate(): ?string
    {
        return null;
    }

    /**
     * Human label for the platform, used only in getMeta().
     */
    protected function platformName(): string
    {
        return 'platform';
    }

    /**
     * The input shape this task expects — 'document', 'image', 'audio', 'text'.
     * Override per task; defaults to 'document'. Transducer tasks have no prompt.
     */
    public function inputKind(): string
    {
        return 'document';
    }

    public function getTask(): string
    {
        if (\defined(static::class.'::TASK')) {
            return (string) constant(static::class.'::TASK');
        }

        return AsTask::deriveName((new \ReflectionClass(static::class))->getShortName());
    }

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        return [] !== $this->inputs($subject);
    }

    public function getMeta(): array
    {
        return [
            'platform' => $this->platformName(),
            'model' => $this->model(),
        ];
    }

    public function run(WorkflowSubjectInterface $subject): TaskResult
    {
        $inputs = $this->inputs($subject);
        $context = $subject instanceof ContextSubjectInterface ? $subject->getWorkflowContext() : [];

        $input = $this->buildInput($subject, $inputs, $context);
        $options = $this->invokeOptions($inputs, $context);

        $result = $this->platform->invoke($this->model(), $input, $options);
        $data = $this->mapResult($result, $inputs, $context);

        return new TaskResult(
            claims: $this->claimMapper->map($data, $this->claimPredicate()),
            meta: new RunMeta(
                model: $this->model(),
                prompt: json_encode(['model' => $this->model(), 'options' => $options], \JSON_THROW_ON_ERROR),
                response: $data,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function inputs(WorkflowSubjectInterface $subject): array
    {
        $inputs = [];
        if ($subject instanceof ImageSubjectInterface) {
            $inputs['image_url'] = $subject->getWorkflowImageUrl();
        }
        if ($subject instanceof TextSubjectInterface) {
            $inputs['text'] = $subject->getWorkflowText();
        }

        return array_filter($inputs, static fn (mixed $value): bool => null !== $value && '' !== $value && [] !== $value);
    }
}
