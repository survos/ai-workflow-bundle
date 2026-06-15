<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\ClaimsBundle\Service\RunMeta;
use Survos\DataContracts\Workflow\AiThumbnailProviderInterface;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\TextSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment as TwigEnvironment;

abstract class AbstractPromptTask implements TaskInterface
{
    protected TwigEnvironment $twig;
    protected HttpClientInterface $httpClient;
    protected TaskClaimMapper $claimMapper;

    public function __construct(
        protected readonly AgentInterface $agent,
    ) {}

    #[Required]
    public function setTaskServices(
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
        TaskClaimMapper $claimMapper,
    ): void {
        $this->twig = $twig;
        $this->httpClient = $httpClient;
        $this->claimMapper = $claimMapper;
    }

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        return $this->inputs($subject) !== [];
    }

    public function getTask(): string
    {
        if (\defined(static::class . '::TASK')) {
            return (string) constant(static::class . '::TASK');
        }

        return AsTask::deriveName((new \ReflectionClass(static::class))->getShortName());
    }

    public function run(WorkflowSubjectInterface $subject): TaskResult
    {
        $inputs = $this->inputs($subject);
        [$systemPrompt, $userPrompt] = $this->buildPrompts($subject, $inputs);

        $imageUrl = $inputs['image_url'] ?? null;
        $attachImage = is_string($imageUrl)
            && $imageUrl !== ''
            && !str_ends_with(strtolower(parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl), '.pdf');

        $userMessage = $attachImage
            ? Message::ofUser($userPrompt, new ImageUrl($imageUrl))
            : Message::ofUser($userPrompt);

        $options = [];
        if ($format = $this->responseFormatClass()) {
            $options['response_format'] = $format;
        }

        $result = $this->agent->call(new MessageBag(Message::forSystem($systemPrompt), $userMessage), $options);
        $data = $this->normalizeContent($result->getContent());
        $tokens = $this->tokenUsage($result->getMetadata()->get('token_usage'));
        if ($tokens !== null) {
            $data['_tokens'] = $tokens;
        }

        return new TaskResult(
            claims: $this->claimsFromData($data),
            appendTasks: $this->followUpTasks($data),
            appendAnalysisTasks: $this->followUpAnalysisTasks($data),
            meta: new RunMeta(
                model: $this->getMeta()['agent'] ?? null,
                prompt: json_encode(['system' => $systemPrompt, 'user' => $userPrompt], JSON_THROW_ON_ERROR),
                response: $data,
                inputTokens: $tokens['prompt'] ?? null,
                outputTokens: $tokens['completion'] ?? null,
            ),
        );
    }

    /**
     * Render the system and user prompts for a subject WITHOUT calling the model —
     * the "description" half of an agent task (the other half being its pre-selected
     * model). Powers prompt previewing / task testing (e.g. the app:tasks TUI).
     *
     * @param array<string, mixed>|null $inputs pre-computed inputs(), or null to recompute
     *
     * @return array{0: string, 1: string} [system, user]
     */
    public function buildPrompts(WorkflowSubjectInterface $subject, ?array $inputs = null): array
    {
        $inputs ??= $this->inputs($subject);
        $tplContext = $this->promptContext($inputs, $this->context($subject));

        $system = trim($this->twig->render($this->systemPromptTemplate(), $tplContext));

        $userTpl = $this->userPromptTemplate();
        $user = $userTpl !== null
            ? trim($this->twig->render($userTpl, $tplContext))
            : trim($this->userPrompt($tplContext));

        return [$system, $user];
    }

    /**
     * The input shape this task expects: 'image' for vision tasks, 'text' otherwise.
     */
    public function inputKind(): string
    {
        return $this instanceof ImageTaskInterface ? 'image' : 'text';
    }

    public function getMeta(): array
    {
        return [
            'agent' => $this->agentName(),
            'template' => $this->systemPromptTemplate(),
        ];
    }

    // ── Template resolution ──────────────────────────────────────────────────

    protected function systemPromptTemplate(): string
    {
        return "@SurvosAiWorkflow/prompt/observation/system/{$this->getTask()}.html.twig";
    }

    /**
     * Return a template path, or null to use userPrompt() instead.
     */
    protected function userPromptTemplate(): ?string
    {
        return "@SurvosAiWorkflow/prompt/observation/user/{$this->getTask()}.html.twig";
    }

    /**
     * Fallback when userPromptTemplate() returns null.
     * Override in tasks that have no Twig template.
     */
    protected function userPrompt(array $context): string
    {
        throw new \LogicException(sprintf(
            '%s must either define a user template or override userPrompt().',
            static::class,
        ));
    }

    // ── Overridable hooks ────────────────────────────────────────────────────

    /**
     * @return class-string|null
     */
    protected function responseFormatClass(): ?string
    {
        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    protected function followUpTasks(array $data): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    protected function followUpAnalysisTasks(array $data): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function claimsFromData(array $data): array
    {
        return $this->claimMapper->map($data);
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function promptContext(array $inputs, array $context = []): array
    {
        return [
            'imageUrl'    => $inputs['image_url'] ?? null,
            'text'        => $inputs['text'] ?? null,
            'html'        => $inputs['html'] ?? null,
            'mime'        => $inputs['mime'] ?? null,
            'inputs'      => $inputs,
            'context'     => $context,
            'prior'       => [],
            'ocr_text'    => $context['ocr_text'] ?? $context['ocrText'] ?? $inputs['text'] ?? null,
            'type'        => $context['type'] ?? $context['content_type'] ?? null,
            'metadata'    => $context['metadata'] ?? $context,
            'description' => $context['description'] ?? null,
            'title'       => $context['title'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function inputs(WorkflowSubjectInterface $subject): array
    {
        $inputs = [];
        if ($subject instanceof ImageSubjectInterface) {
            $inputs['image_url'] = $subject instanceof AiThumbnailProviderInterface
                ? $subject->getAiSmallUrl()
                : $subject->getWorkflowImageUrl();
        }
        if ($subject instanceof TextSubjectInterface) {
            $inputs['text'] = $subject->getWorkflowText();
        }
        if ($subject instanceof ContextSubjectInterface) {
            foreach (['html', 'mime', 'max_pages'] as $key) {
                if (array_key_exists($key, $subject->getWorkflowContext())) {
                    $inputs[$key] = $subject->getWorkflowContext()[$key];
                }
            }
        }

        return array_filter($inputs, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(WorkflowSubjectInterface $subject): array
    {
        return $subject instanceof ContextSubjectInterface ? $subject->getWorkflowContext() : [];
    }

    protected function fetchImage(string $url): Image
    {
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);
            $binary = file_get_contents($path);
            if ($binary === false) {
                throw new \RuntimeException("Cannot read local image: {$path}");
            }

            return new Image($binary, mime_content_type($path) ?: 'image/jpeg');
        }

        $response = $this->httpClient->request('GET', $url);
        $contentType = $response->getHeaders()['content-type'][0] ?? 'image/jpeg';

        return new Image($response->getContent(), trim(explode(';', $contentType)[0]));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContent(mixed $content): array
    {
        if ($content instanceof \JsonSerializable) {
            $content = $content->jsonSerialize();
        }
        if (is_array($content)) {
            return $content;
        }

        $stripped = preg_replace('/^```(?:json)?\s*/i', '', trim((string) $content));
        $stripped = preg_replace('/\s*```\s*$/', '', $stripped ?? (string) $content);
        $decoded = json_decode(trim($stripped ?? (string) $content), true);

        return is_array($decoded) ? $decoded : ['raw' => (string) $content];
    }

    /**
     * @return array<string, int>|null
     */
    private function tokenUsage(mixed $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        return [
            'prompt'           => $usage->getPromptTokens(),
            'completion'       => $usage->getCompletionTokens(),
            'total'            => $usage->getTotalTokens(),
            'cached'           => $usage->getCachedTokens(),
            'cache_creation'   => $usage->getCacheCreationTokens(),
            'cache_read'       => $usage->getCacheReadTokens(),
        ];
    }

    private function agentName(): string
    {
        try {
            $rc = new \ReflectionClass(static::class);
            foreach ($rc->getConstructor()?->getParameters() ?? [] as $param) {
                foreach ($param->getAttributes(Autowire::class) as $attr) {
                    $args = $attr->getArguments();
                    $service = $args['service'] ?? $args[0] ?? null;
                    if (is_string($service) && str_starts_with($service, 'ai.agent.')) {
                        return str_replace('ai.agent.', '', $service);
                    }
                }
            }
        } catch (\Throwable) {
        }

        return 'unknown';
    }
}
