<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\AiWorkflowBundle\Contract\WorkflowSubjectInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function Symfony\Component\String\u;

#[AsCommand('ai:task:run', 'Run a single AI workflow task against a specific entity.')]
final class RunTaskCommand
{
    public function __construct(
        private readonly TaskRegistry           $taskRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClaimIngestor          $claimIngestor,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Task name (e.g. observe, triage, ocr_mistral)')] string $task,
        #[Argument('Entity class short name or APP_ENTITY_* global key (e.g. GalleryImage)')] string $entity,
        #[Argument('Entity identifier (id, code, ULID, …)')] string $id,
        #[Option('JSON operator hints merged into workflow context (e.g. \'{"content_type":"postcard"}\')')] ?string $operator = null,
        #[Option('Simple string hint added to operator context (e.g. "use highres")')] ?string $hint = null,
        #[Option('Override which task to run, ignoring the <task> argument (e.g. --task observe_hires)', name: 'task')] ?string $runTask = null,
        #[Option('Override image URL (e.g. imgproxy thumbnail) instead of entity\'s getWorkflowImageUrl()')] ?string $thumbnailUrl = null,
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

        // ── Resolve task ─────────────────────────────────────────────────────
        $task = $runTask ?? $task;
        $taskObj = $this->taskRegistry->get($task);
        if ($taskObj === null) {
            $io->error(sprintf(
                'Unknown task "%s". Registered: %s',
                $task,
                implode(', ', array_keys($this->taskRegistry->getTaskMap())) ?: '(none)',
            ));
            return Command::FAILURE;
        }

        // ── Resolve entity class ─────────────────────────────────────────────
        $class = $this->resolveClass($entity);
        if ($class === null) {
            $io->error(sprintf('Could not resolve entity class for "%s".', $entity));
            return Command::FAILURE;
        }

        // ── Load entity ──────────────────────────────────────────────────────
        $entityObj = $this->entityManager->getRepository($class)->find($id);
        if ($entityObj === null) {
            $io->error(sprintf('%s #%s not found.', $class, $id));
            return Command::FAILURE;
        }

        if (!$entityObj instanceof WorkflowSubjectInterface) {
            $io->error(sprintf('%s does not implement WorkflowSubjectInterface.', $class));
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

        $imageUrl = $entityObj instanceof \Survos\AiWorkflowBundle\Contract\ImageSubjectInterface
            ? $entityObj->getWorkflowImageUrl() : null;
        if ($imageUrl !== null) {
            $io->writeln(sprintf('  <comment>image:</comment>    %s', $imageUrl));
        }
        if ($operatorHints !== []) {
            $io->writeln(sprintf('  <comment>operator:</comment> %s',
                json_encode($operatorHints, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
        if ($entityObj instanceof \Survos\AiWorkflowBundle\Contract\ContextSubjectInterface) {
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
            $conf  = sprintf('%.0f%%', $claim->confidence * 100);
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

            if ($result->appendTasks !== []) {
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
