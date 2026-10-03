<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\Task\Observation\AnnotateHandwritingTask;
use Survos\AiWorkflowBundle\Task\Observation\FlorenceTask;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;

final class OptionalTaskDependencyTest extends TestCase
{
    public function testMissingAgentFailsOnlyWhenTaskRuns(): void
    {
        $task = new AnnotateHandwritingTask();
        self::assertSame('htr_annotate', $task->getTask());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ai.agent.mistral_vision');
        $task->run($this->createMock(WorkflowSubjectInterface::class));
    }

    public function testMissingPlatformFailsOnlyWhenTaskRuns(): void
    {
        $task = new FlorenceTask();
        self::assertSame('florence', $task->getTask());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ai-tools');
        $task->run($this->createMock(WorkflowSubjectInterface::class));
    }
}
