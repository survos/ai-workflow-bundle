<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

abstract class AbstractAnalysisTask extends AbstractPromptTask implements AnalysisTaskInterface
{
    protected function systemPromptTemplate(): string
    {
        return '@SurvosAiWorkflow/prompt/analysis/system.html.twig';
    }

    protected function userPromptTemplate(): ?string
    {
        $path = \dirname(__DIR__, 2) . "/templates/prompt/analysis/user/{$this->getTask()}.html.twig";

        return is_file($path)
            ? "@SurvosAiWorkflow/prompt/analysis/user/{$this->getTask()}.html.twig"
            : null;
    }

    protected function userPrompt(array $context): string
    {
        return 'Analyze the observation evidence and extract the requested information.';
    }
}
