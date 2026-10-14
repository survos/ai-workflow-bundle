<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\Entity\Subject;
use Survos\AiWorkflowBundle\Tests\Fixtures\SubjectFlowKernel;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow as Flow;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Workflow\WorkflowBundle;

#[CoversClass(Flow::class)]
final class SubjectFlowTest extends TestCase
{
    #[Test]
    public function pendingQueuesFinishBeforeAdvancingAndPublicationTerminates(): void
    {
        if (!\class_exists(WorkflowBundle::class)) {
            self::markTestSkipped('Native workflow discovery requires Symfony 8.2.');
        }
        require_once __DIR__.'/Fixtures/SubjectFlowKernel.php';
        $directory = \sys_get_temp_dir().'/subject-flow-'.\bin2hex(\random_bytes(6));
        $kernel = new SubjectFlowKernel($directory);
        try {
            $kernel->boot();
            $workflow = $kernel->getContainer()->get('test.workflow');
            $subject = new Subject('test', '1');
            self::assertSame([Flow::PLACE_NEW => 1], $workflow->getMarking($subject)->getPlaces());
            $workflow->apply($subject, Flow::TRANSITION_PREPARE);
            self::assertFalse($workflow->can($subject, Flow::TRANSITION_OBSERVE));
            $subject->addPendingStep('first', Flow::TRANSITION_OBSERVE);
            $subject->addPendingStep('second', Flow::TRANSITION_OBSERVE);
            $workflow->apply($subject, Flow::TRANSITION_OBSERVE);
            self::assertSame('first', $subject->shiftPendingStep(Flow::TRANSITION_OBSERVE));
            self::assertTrue($workflow->can($subject, Flow::TRANSITION_OBSERVE));
            self::assertFalse($workflow->can($subject, Flow::TRANSITION_ANALYZE));
            self::assertSame('second', $subject->shiftPendingStep(Flow::TRANSITION_OBSERVE));
            self::assertFalse($workflow->can($subject, Flow::TRANSITION_OBSERVE));
            $subject->addPendingStep('analysis', Flow::TRANSITION_ANALYZE);
            $workflow->apply($subject, Flow::TRANSITION_ANALYZE);
            self::assertTrue($workflow->can($subject, Flow::TRANSITION_ANALYZE));
            self::assertFalse($workflow->can($subject, Flow::TRANSITION_REVIEW));
            $subject->shiftPendingStep(Flow::TRANSITION_ANALYZE);
            self::assertFalse($workflow->can($subject, Flow::TRANSITION_ANALYZE));
            $workflow->apply($subject, Flow::TRANSITION_REVIEW);
            self::assertSame([Flow::TRANSITION_PUBLISH], $workflow->getMetadataStore()->getPlaceMetadata(Flow::PLACE_REVIEWED)['next']);
            $workflow->apply($subject, Flow::TRANSITION_PUBLISH);
            self::assertSame(Flow::PLACE_PUBLISHED, $subject->getMarking());
            self::assertArrayNotHasKey('next', $workflow->getMetadataStore()->getPlaceMetadata(Flow::PLACE_PUBLISHED));
            foreach ($workflow->getDefinition()->getTransitions() as $transition) {
                self::assertSame('ai_subject.'.$transition->getName(), $workflow->getMetadataStore()->getMetadata('transport', $transition));
            }
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($directory);
        }
    }
}
