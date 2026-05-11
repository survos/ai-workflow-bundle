<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\AiClaimsBundle\Service\ClaimIngestor;
use Survos\AiClaimsBundle\Service\RawClaim;
use Survos\AiClaimsBundle\Service\RunMeta;
use Survos\AiWorkflowBundle\Contract\WorkflowSubjectInterface;

final class TaskRunner
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly ClaimIngestor $claimIngestor,
    ) {
    }

    /**
     * Runs the next queued task for the subject.
     *
     * Returns the task name that was consumed, or null when there was no work.
     */
    public function runNext(WorkflowSubjectInterface $subject): ?string
    {
        if ($subject->isWorkflowLocked()) {
            return null;
        }

        $queue = $subject->getWorkflowQueue();
        if ($queue === []) {
            return null;
        }

        $taskName = array_shift($queue);
        $subject->setWorkflowQueue($queue);

        $task = $this->registry->get($taskName);
        if ($task === null) {
            $this->recordSystemRun($subject, $taskName, [
                new RawClaim('ai:taskSkipped', $taskName, 1.0, 'No registered workflow task.'),
            ]);

            return $taskName;
        }

        if (!$task->supports($subject)) {
            $this->recordSystemRun($subject, $taskName, [
                new RawClaim('ai:taskSkipped', $taskName, 1.0, 'Task supports() returned false.'),
            ]);

            return $taskName;
        }

        $startedAt = microtime(true);
        try {
            $result = $task->run($subject);
        } catch (\Throwable $e) {
            $this->recordSystemRun($subject, $taskName, [
                new RawClaim('ai:taskFailed', $taskName, 1.0, $e->getMessage()),
            ], new RunMeta(durationMs: $this->durationMs($startedAt)));

            return $taskName;
        }

        $meta = $this->withDuration($result->meta, $this->durationMs($startedAt));
        $this->claimIngestor->record(
            scope: $subject->getWorkflowScope(),
            subjectType: $subject::class,
            subjectId: $subject->getWorkflowSubjectId(),
            source: $taskName . '@1.0',
            rawClaims: $result->claims,
            meta: $meta,
        );

        if ($result->appendTasks !== []) {
            $subject->setWorkflowQueue(array_values([
                ...$subject->getWorkflowQueue(),
                ...$result->appendTasks,
            ]));
        }

        return $taskName;
    }

    /**
     * @param list<RawClaim> $claims
     */
    private function recordSystemRun(
        WorkflowSubjectInterface $subject,
        string $taskName,
        array $claims,
        ?RunMeta $meta = null,
    ): void {
        $this->claimIngestor->record(
            scope: $subject->getWorkflowScope(),
            subjectType: $subject::class,
            subjectId: $subject->getWorkflowSubjectId(),
            source: $taskName . '@system',
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
        if ($meta === null) {
            return new RunMeta(durationMs: $durationMs);
        }

        if ($meta->durationMs !== null) {
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
