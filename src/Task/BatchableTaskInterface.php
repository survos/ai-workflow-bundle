<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\DataContracts\Workflow\WorkflowSubjectInterface;

/**
 * A task that can also run through a provider's batch API (half price, results in minutes to
 * 24 hours) instead of one synchronous call per subject.
 *
 * The rule: a task's batch path and its sync path share ONE request builder and ONE response
 * parser, so a subject gets identical claims whichever way it ran. batchRequest() is the body
 * run() would POST; batchResult() is what run() does with the response.
 *
 * Plain arrays on purpose: this bundle does not depend on a batch client. The app turns
 * batchRequest() into its batch library's request (survos/ai-batch-bundle: BatchRequest::raw())
 * and hands the provider's response body back to batchResult().
 */
interface BatchableTaskInterface extends TaskInterface
{
    /** The provider whose batch API takes these requests: 'mistral' | 'openai' | 'anthropic'. */
    public function batchProvider(): string;

    /**
     * Whether this subject can go in a provider batch. The provider fetches inputs itself, so a
     * subject whose input is local (file://) or private (s3://) cannot, and runs sync instead.
     */
    public function supportsBatch(WorkflowSubjectInterface $subject): bool;

    /**
     * The request run() would send, including `model` in the body.
     *
     * @return array{endpoint: string, body: array<string, mixed>}
     */
    public function batchRequest(WorkflowSubjectInterface $subject): array;

    /**
     * One provider response body (what the sync call would have returned) → the same TaskResult
     * run() produces.
     *
     * @param array<string, mixed> $responseBody
     */
    public function batchResult(WorkflowSubjectInterface $subject, array $responseBody): TaskResult;
}
