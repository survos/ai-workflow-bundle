<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Traits\PendingStepsInterface;
use Survos\AiWorkflowBundle\Task\TaskRunner;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Workflow\Registry;
use function Symfony\Component\String\u;

#[AsCommand('ai:task:run', 'Run an AI task or workflow transition against a specific entity.')]
final class RunTaskCommand
{
    public function __construct(
        private readonly TaskRegistry           $taskRegistry,
        private readonly TaskRunner             $taskRunner,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClaimIngestor          $claimIngestor,
        private readonly Registry               $workflowRegistry,
    ) {
    }

    /** Transition names recognised as full-phase runs rather than single tasks. */
    private const TRANSITIONS = [
        SubjectFlow::TRANSITION_PREPARE,
        SubjectFlow::TRANSITION_OBSERVE,
        SubjectFlow::TRANSITION_ANALYZE,
        SubjectFlow::TRANSITION_REVIEW,
        SubjectFlow::TRANSITION_PUBLISH,
    ];

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity class short name or FQCN (e.g. Subject)')] string $entity,
        #[Argument('Entity identifier (id, ULID, …)')] string $id,
        #[Option('Fire a specific workflow transition (observe, analyze, …) — runs all pending steps for that phase')] ?string $transition = null,
        #[Option('Run a single named task (observe_hires, extract_metadata, …) — debug/override mode')] ?string $task = null,
        #[Option('JSON operator hints merged into workflow context')] ?string $operator = null,
        #[Option('Simple string hint added to operator context')] ?string $hint = null,
        #[Option('Override image URL instead of entity\'s getWorkflowImageUrl()')] ?string $thumbnailUrl = null,
        #[Option('Persist claims to the database')] ?bool $persist = null,
        #[Option('Pretty-print the full claim data')] bool $pretty = false,
    ): int {
        $persist ??= true;
        $operatorHints = [];
        if ($operator !== null) {
            $operatorHints = json_decode($operator, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($hint !== null) {
            $operatorHints['hint'] = $hint;
        }

        // ── Resolve entity class + load ──────────────────────────────────────
        $class = $this->resolveClass($entity);
        if ($class === null) {
            $io->error(sprintf('Could not resolve entity class for "%s".', $entity));
            return Command::FAILURE;
        }

        $entityObj = $this->entityManager->getRepository($class)->find($id);
        if ($entityObj === null) {
            $io->error(sprintf('%s #%s not found.', $class, $id));
            return Command::FAILURE;
        }

        if (!$entityObj instanceof WorkflowSubjectInterface) {
            $io->error(sprintf('%s does not implement WorkflowSubjectInterface.', $class));
            return Command::FAILURE;
        }

        // ── Transition mode: fire one or all transitions ─────────────────────
        if ($task === null) {
            $transitions = $transition !== null ? [$transition] : self::TRANSITIONS;
            return $this->runTransitions($io, $entityObj, $transitions, $persist);
        }

        // ── Single-task override mode ─────────────────────────────────────────
        $taskObj = $this->taskRegistry->get($task);
        if ($taskObj === null) {
            $io->error(sprintf(
                'Unknown task "%s". Registered: %s',
                $task,
                implode(', ', array_keys($this->taskRegistry->getTaskMap())) ?: '(none)',
            ));
            return Command::FAILURE;
        }

        if (!$taskObj->supports($entityObj)) {
            $io->warning(sprintf('Task "%s" does not support %s — supports() returned false.', $task, $class));
            return Command::FAILURE;
        }

        // ── Inject operator hints via context (if subject supports it) ────────
        if ($operatorHints !== [] && method_exists($entityObj, 'mergeWorkflowContext')) {
            $entityObj->mergeWorkflowContext($operatorHints);
        }

        // ── Override image URL (e.g. imgproxy thumbnail) ─────────────────────
        if ($thumbnailUrl !== null && property_exists($entityObj, 'resolvedImageUrl')) {
            $entityObj->resolvedImageUrl = $thumbnailUrl;
        }

        // ── Show inputs ──────────────────────────────────────────────────────
        $io->section(sprintf('Running "%s" on %s #%s', $task, (new \ReflectionClass($class))->getShortName(), $id));

        $imageUrl = $entityObj instanceof \Survos\DataContracts\Workflow\ImageSubjectInterface
            ? $entityObj->getWorkflowImageUrl() : null;
        if ($imageUrl !== null) {
            $io->writeln(sprintf('  <comment>image:</comment>    %s', $imageUrl));
        }
        if ($operatorHints !== []) {
            $io->writeln(sprintf('  <comment>operator:</comment> %s',
                json_encode($operatorHints, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
        if ($entityObj instanceof \Survos\DataContracts\Workflow\ContextSubjectInterface) {
            $ctx = array_filter($entityObj->getWorkflowContext());
            if ($ctx) {
                $io->writeln(sprintf('  <comment>context:</comment>  %s',
                    json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            }
        }
        $io->newLine();

        $result = $taskObj->run($entityObj);

        // ── Output ───────────────────────────────────────────────────────────
        $io->writeln(sprintf('  Claims produced:    <info>%d</info>', count($result->claims)));
        $io->writeln(sprintf('  Follow-up tasks:    <info>%s</info>', $result->appendTasks ? implode(', ', $result->appendTasks) : '—'));
        if ($result->meta?->model) {
            $io->writeln(sprintf('  Model:              <info>%s</info>', $result->meta->model));
        }
        if ($result->meta?->durationMs) {
            $io->writeln(sprintf('  Duration:           <info>%d ms</info>', $result->meta->durationMs));
        }
        if ($result->meta?->inputTokens) {
            $io->writeln(sprintf('  Tokens in/out:      <info>%d / %d</info>', $result->meta->inputTokens, $result->meta->outputTokens ?? 0));
        }

        $io->newLine();

        foreach ($result->claims as $claim) {
            $value = is_array($claim->value)
                ? json_encode($claim->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $claim->value;
            $conf  = sprintf('%d%%', $claim->confidence);
            $basis = $claim->basis ? sprintf(' [%s]', mb_substr($claim->basis, 0, 80)) : '';

            // Short values on one line; long values wrapped on next line
            if (mb_strlen($value) <= 80) {
                $io->writeln(sprintf('  <info>%-32s</info> %s  <comment>%s</comment>%s',
                    $claim->predicate, $value, $conf, $basis));
            } else {
                $io->writeln(sprintf('  <info>%-32s</info> <comment>%s</comment>%s', $claim->predicate, $conf, $basis));
                $io->writeln('    ' . wordwrap($value, 110, "\n    "));
            }
        }

        if ($pretty && $result->meta?->response) {
            $io->newLine();
            $io->writeln(json_encode($result->meta->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // ── Persist ──────────────────────────────────────────────────────────
        if ($persist) {
            $this->claimIngestor->record(
                scope: $entityObj->getWorkflowScope(),
                subjectType: $class,
                subjectId: $entityObj->getWorkflowSubjectId(),
                source: $task . '@1.0',
                rawClaims: $result->claims,
                meta: $result->meta,
            );

            if ($result->appendTasks !== [] && $entityObj instanceof PendingStepsInterface) {
                foreach ($result->appendTasks as $step) {
                    $entityObj->addPendingStep($step, SubjectFlow::TRANSITION_OBSERVE);
                }
            }

            $this->entityManager->flush();
            $io->success('Claims persisted.');
        } else {
            $io->note('Dry run — claims not persisted.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $transitions
     */
    private function runTransitions(SymfonyStyle $io, WorkflowSubjectInterface $subject, array $transitions, bool $persist): int
    {
        $workflow  = $this->workflowRegistry->get($subject, SubjectFlow::WORKFLOW_NAME);
        $shortName = (new \ReflectionClass($subject))->getShortName();
        $applied   = 0;

        foreach ($transitions as $t) {
            if (!$workflow->can($subject, $t)) {
                $io->writeln(sprintf('  <comment>skip</comment>  %s (not available from <info>%s</info>)', $t, $subject->getMarking()));
                continue;
            }

            $io->writeln(sprintf('  <info>apply</info> %s (from <comment>%s</comment>)', $t, $subject->getMarking()));
            $workflow->apply($subject, $t, ['cascade' => 'none']);
            $io->writeln(sprintf('         → <info>%s</info>', $subject->getMarking()));
            $applied++;

            if ($persist) {
                $this->entityManager->persist($subject);
                $this->entityManager->flush();
            }
        }

        if ($applied === 0) {
            $io->warning(sprintf('No transitions could be applied to %s #%s (marking: %s).', $shortName, $subject->getWorkflowSubjectId(), $subject->getMarking()));
            return Command::FAILURE;
        }

        $msg = $persist
            ? sprintf('%d transition(s) applied. Final marking: %s', $applied, $subject->getMarking())
            : sprintf('Dry run — %d transition(s) would apply.', $applied);

        $io->success($msg);
        return Command::SUCCESS;
    }

    private function resolveClass(string $name): ?string
    {
        if (class_exists($name)) {
            return $name;
        }

        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $meta) {
            $computed = u(ltrim($meta->getName(), '\\'))->replace('\\', '_')->snake()->upper()->toString();
            if ($computed === strtoupper($name)) {
                return $meta->getName();
            }
            if (strtolower((new \ReflectionClass($meta->getName()))->getShortName()) === strtolower($name)) {
                return $meta->getName();
            }
        }

        return null;
    }
}
