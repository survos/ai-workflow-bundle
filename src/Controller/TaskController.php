<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Controller;

use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Task\AnalysisTaskInterface;
use Survos\AiWorkflowBundle\Task\ContextTaskInterface;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\ObservationTaskInterface;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TextTaskInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/ai-workflow/tasks')]
final class TaskController extends AbstractController
{
    public function __construct(
        private readonly TaskRegistry $taskRegistry,
    ) {
    }

    #[Route('', name: 'survos_ai_workflow_tasks')]
    public function index(): Response
    {
        $groups = [
            'Observation' => [],
            'Analysis' => [],
            'Other' => [],
        ];
        foreach ($this->taskRegistry->getTaskMap() as $taskName => $serviceId) {
            $task = $this->taskRegistry->get($taskName);
            if ($task === null) {
                continue;
            }

            $row = [
                'name'         => $taskName,
                'serviceId'    => $serviceId,
                'meta'         => $task->getMeta(),
                'capabilities' => $this->capabilities($task),
                'description'  => $this->taskRegistry->getDescriptor($taskName)->description,
                'prompts'      => $this->promptSources($taskName),
            ];

            $groups[$this->group($task)][$taskName] = $row;
        }

        foreach ($groups as &$tasks) {
            ksort($tasks);
        }
        unset($tasks);

        return $this->render('@SurvosAiWorkflow/task/index.html.twig', [
            'groups' => array_filter($groups),
        ]);
    }

    #[Route('/{taskName}', name: 'survos_ai_workflow_task', requirements: ['taskName' => '[A-Za-z0-9_.:-]+'])]
    public function show(string $taskName): Response
    {
        $task = $this->taskRegistry->get($taskName);
        if ($task === null) {
            throw $this->createNotFoundException(sprintf('Unknown AI workflow task "%s".', $taskName));
        }

        return $this->render('@SurvosAiWorkflow/task/show.html.twig', [
            'taskName' => $taskName,
            'serviceId' => $this->taskRegistry->getTaskMap()[$taskName] ?? null,
            'meta' => $task->getMeta(),
            'capabilities' => $this->capabilities($task),
            'prompts' => $this->promptSources($taskName),
        ]);
    }

    private function group(TaskInterface $task): string
    {
        return match (true) {
            $task instanceof ObservationTaskInterface => 'Observation',
            $task instanceof AnalysisTaskInterface => 'Analysis',
            default => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    private function capabilities(TaskInterface $task): array
    {
        $capabilities = [];
        if ($task instanceof ImageTaskInterface) {
            $capabilities[] = 'image';
        }
        if ($task instanceof TextTaskInterface) {
            $capabilities[] = 'text';
        }
        if ($task instanceof ContextTaskInterface) {
            $capabilities[] = 'subject context';
        }

        return $capabilities;
    }

    /**
     * @return array<string, string|null>
     */
    private function promptSources(string $taskName): array
    {
        $base = \dirname(__DIR__, 2) . '/templates/prompt';

        // Observation layout: observation/system/{slug}.html.twig
        $obsSystem = $this->readPrompt("{$base}/observation/system/{$taskName}.html.twig");
        if ($obsSystem !== null) {
            return [
                'system' => $obsSystem,
                'user'   => $this->readPrompt("{$base}/observation/user/{$taskName}.html.twig"),
            ];
        }

        // Analysis layout: shared system + optional per-task user
        $anaSystem = $this->readPrompt("{$base}/analysis/system.html.twig");
        if ($anaSystem !== null) {
            return [
                'system' => $anaSystem,
                'user'   => $this->readPrompt("{$base}/analysis/user/{$taskName}.html.twig"),
            ];
        }

        return ['system' => null, 'user' => null];
    }

    private function readPrompt(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        // Later: render with Twig against a sample subject/context to inspect the real model prompt.
        $source = file_get_contents($path);

        return $source === false ? null : $source;
    }
}
