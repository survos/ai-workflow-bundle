<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\AiWorkflowBundle\Traits\PendingStepsInterface;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\ClaimsBundle\Service\RunMeta;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\StateBundle\Traits\MarkingInterface;

final class TaskRunner
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly ClaimIngestor $claimIngestor,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function runNext(WorkflowSubjectInterface&MarkingInterface&PendingStepsInterface $subject, string $phase = SubjectFlow::TRANSITION_OBSERVE): ?string
    {
        if ($subject->isWorkflowLocked()) {
            return null;
        }

        if (0 === $subject->pendingCount($phase)) {
            return null;
        }

        $taskName = $subject->shiftPendingStep($phase);
        $subjectId = $subject->getWorkflowSubjectId();

        $task = $this->registry->get($taskName);
        if (null === $task) {
            $this->logger->info('[{task}] skipped — not registered', ['task' => $taskName, 'subject' => $subjectId]);
            $this->recordSystemRun($subject, $taskName, [
                new RawClaim('ai:taskSkipped', $taskName, 100, 'No registered workflow task.'),
            ]);

            return $taskName;
        }

        if (!$task->supports($subject)) {
            $this->logger->info('[{task}] skipped — supports() false', ['task' => $taskName, 'subject' => $subjectId]);
            $this->recordSystemRun($subject, $taskName, [
                new RawClaim('ai:taskSkipped', $taskName, 100, 'Task supports() returned false.'),
            ]);

            return $taskName;
        }

        $imageUrl = $subject instanceof ImageSubjectInterface ? $subject->getWorkflowImageUrl() : null;
        if (null !== $imageUrl) {
            $this->logger->info('[{task}] image: {url}', ['task' => $taskName, 'url' => $imageUrl]);
        }

        $startedAt = microtime(true);
        try {
            $result = $task->run($subject);
        } catch (\Throwable $e) {
            $this->logger->error('[{task}] FAILED: {error}', ['task' => $taskName, 'error' => $e->getMessage()]);
            $this->recordSystemRun($subject, $taskName, [
                new RawClaim('ai:taskFailed', $taskName, 100, $e->getMessage()),
            ], new RunMeta(durationMs: $this->durationMs($startedAt)));

            return $taskName;
        }

        $durationMs = $this->durationMs($startedAt);
        $meta = $this->withDuration($result->meta, $durationMs);

        // Log prompts and response at debug level (-vv)
        if (null !== $meta->prompt) {
            $prompts = json_decode($meta->prompt, true);
            if (is_array($prompts)) {
                $this->logger->info('[{task}] system prompt:{nl}{prompt}', [
                    'task' => $taskName,
                    'nl' => "\n",
                    'prompt' => $prompts['system'] ?? '',
                ]);
                $this->logger->info('[{task}] user prompt:{nl}{prompt}', [
                    'task' => $taskName,
                    'nl' => "\n",
                    'prompt' => $prompts['user'] ?? '',
                ]);
            }
        }

        if (null !== $meta->response) {
            $this->logger->info('[{task}] response:{nl}{json}', [
                'task' => $taskName,
                'nl' => "\n",
                'json' => json_encode($meta->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $tokens = array_filter([
            'in' => $meta->inputTokens,
            'out' => $meta->outputTokens,
            'cached' => $meta->imageTokens,
        ]);
        $this->logger->info('[{task}] done in {ms}ms{tokens}', [
            'task' => $taskName,
            'ms' => $durationMs,
            'tokens' => $tokens ? ' tokens='.json_encode($tokens) : '',
        ]);

        $this->claimIngestor->record(
            scope: $subject->getWorkflowScope(),
            subjectType: $subject->getWorkflowSubjectType(),
            subjectId: $subjectId,
            source: $taskName.'@1.0',
            rawClaims: $result->claims,
            meta: $meta,
        );

        foreach ($result->appendTasks as $step) {
            $subject->addPendingStep($step, SubjectFlow::TRANSITION_OBSERVE);
        }

        foreach ($result->appendAnalysisTasks as $step) {
            $subject->addPendingStep($step, SubjectFlow::TRANSITION_ANALYZE);
        }

        return $taskName;
    }

    private function recordSystemRun(
        WorkflowSubjectInterface $subject,
        string $taskName,
        array $claims,
        ?RunMeta $meta = null,
    ): void {
        $this->claimIngestor->record(
            scope: $subject->getWorkflowScope(),
            subjectType: $subject->getWorkflowSubjectType(),
            subjectId: $subject->getWorkflowSubjectId(),
            source: $taskName.'@system',
            rawClaims: $claims,
            meta: $meta,
        );
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function withDuration(?RunMeta $meta, int $durationMs): RunMeta
    {
        if (null === $meta) {
            return new RunMeta(durationMs: $durationMs);
        }

        if (null !== $meta->durationMs) {
            return $meta;
        }

        return new RunMeta(
            model: $meta->model,
            prompt: $meta->prompt,
            response: $meta->response,
            inputTokens: $meta->inputTokens,
            outputTokens: $meta->outputTokens,
            imageTokens: $meta->imageTokens,
            durationMs: $durationMs,
        );
    }
}
