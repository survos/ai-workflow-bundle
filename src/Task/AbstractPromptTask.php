<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task;

use Survos\ClaimsBundle\Service\RunMeta;
use Survos\DataContracts\Workflow\AiThumbnailProviderInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\TextSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;
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

        return $this->taskResultFromData(
            $this->normalizeContent($result->getContent()),
            $this->tokenUsage($result->getMetadata()->get('token_usage')),
            $systemPrompt,
            $userPrompt,
            $this->modelId($result),
        );
    }

    /**
     * The model that actually answered, for RunMeta::$model.
     *
     * This used to pass `getMeta()['agent']` -- the AGENT name from the bundle config
     * ("description", "item_synthesis"), not a model at all. Every ClaimRun therefore
     * recorded a label you cannot price: working out what a 6M-token run had cost meant
     * opening ai.yaml to see which model that agent was wired to, and the answer was
     * only as current as the config you happened to read.
     *
     * The provider's own response body is the authoritative answer and is better than
     * the config in one way that matters for billing: it names the exact deployed
     * version ("gpt-4o-mini-2024-07-18"), which is what the invoice is actually priced
     * against, not the floating alias the config asked for.
     *
     * Falls back to the agent name so a provider that omits `model` still records
     * something identifiable rather than null.
     */
    private function modelId(ResultInterface $result): ?string
    {
        try {
            $raw = $result->getRawResult()?->getData();
        } catch (\Throwable) {
            // A streamed or replayed result may have no raw body to read. Never let
            // bookkeeping break the task whose output we already have.
            $raw = null;
        }

        $model = \is_array($raw) ? ($raw['model'] ?? null) : null;

        // symfony/ai 0.13 moved the model to a plain string (Input::getModel(): string);
        // older versions handed back a Model object. Accept either, so upgrading the
        // platform does not silently start writing "Object" into the column.
        if (\is_object($model)) {
            $model = method_exists($model, 'getName') ? $model->getName() : null;
        }

        return \is_string($model) && trim($model) !== ''
            ? trim($model)
            : ($this->getMeta()['agent'] ?? null);
    }

    /**
     * The one path from a model's parsed answer to a TaskResult -- shared by run() and
     * chatBatchResult(), so a subject gets identical claims whichever way it ran.
     *
     * @param array<string,mixed>      $data
     * @param array<string,mixed>|null $tokens
     */
    private function taskResultFromData(array $data, ?array $tokens, string $systemPrompt, string $userPrompt, ?string $model): TaskResult
    {
        if ($tokens !== null) {
            $data['_tokens'] = $tokens;
        }

        return new TaskResult(
            claims: $this->claimsFromData($data),
            appendTasks: $this->followUpTasks($data),
            appendAnalysisTasks: $this->followUpAnalysisTasks($data),
            meta: new RunMeta(
                model: $model,
                prompt: json_encode(['system' => $systemPrompt, 'user' => $userPrompt], JSON_THROW_ON_ERROR),
                response: $data,
                inputTokens: $tokens['prompt'] ?? null,
                outputTokens: $tokens['completion'] ?? null,
            ),
        );
    }

    /**
     * BatchableTaskInterface::batchRequest() for a chat-completions prompt task: exactly what run()
     * sends -- same prompts, same image, the agent's model, and the response schema Symfony AI
     * builds from responseFormatClass() -- as an OpenAI-shaped /v1/chat/completions body.
     *
     * @return array{endpoint: string, body: array<string, mixed>}
     */
    protected function chatBatchRequest(WorkflowSubjectInterface $subject): array
    {
        $model = $this->agentModel();
        $inputs = $this->inputs($subject);
        [$systemPrompt, $userPrompt] = $this->buildPrompts($subject, $inputs);

        $user = [['type' => 'text', 'text' => $userPrompt]];
        $imageUrl = $inputs['image_url'] ?? null;
        if (is_string($imageUrl) && $imageUrl !== '' && !str_ends_with(strtolower(parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl), '.pdf')) {
            $image = ['url' => $imageUrl];
            if (($detail = $this->batchImageDetail()) !== null) {
                $image['detail'] = $detail;
            }
            $user[] = ['type' => 'image_url', 'image_url' => $image];
        }

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $user],
            ],
        ];
        if ($format = $this->responseFormatClass()) {
            $body['response_format'] = (new ResponseFormatFactory())->create($format);
        }

        return ['endpoint' => '/v1/chat/completions', 'body' => $body];
    }

    /**
     * The configured model behind $this->agent. Symfony AI's Agent exposes it; in dev the profiler
     * wraps it in a TraceableAgent, which does not, so unwrap that (read-only, debug wrapper only).
     */
    private function agentModel(): string
    {
        $agent = $this->agent;
        while ($agent instanceof \Symfony\AI\Agent\TraceableAgent) {
            $agent = (new \ReflectionProperty($agent, 'agent'))->getValue($agent);
        }
        if (!$agent instanceof Agent) {
            throw new \LogicException(sprintf('%s: batching needs the agent\'s model, and %s does not expose one.', static::class, get_debug_type($agent)));
        }

        return $agent->getModel();
    }

    /**
     * OpenAI image `detail` for the batch request: 'low' is a flat ~2,833 tokens on gpt-4o-mini
     * instead of tiling (~8,500 for a 512px thumbnail), and measured on omeka/wej it halves the
     * cost with no loss -- observe's inventory was if anything fuller, and merit's scores moved
     * less than they do between two identical runs. Null (the default) leaves it to the provider:
     * a task reading fine print or handwriting wants the tiles.
     *
     * Only the batch path can set this: the sync path goes through Symfony AI's ImageUrl, whose
     * normalizer emits {url} alone, so a synchronous run of the same task pays full tiling.
     */
    protected function batchImageDetail(): ?string
    {
        return null;
    }

    /**
     * BatchableTaskInterface::batchResult() for chatBatchRequest(): one chat-completions response
     * body -> the same TaskResult run() would have produced.
     *
     * @param array<string, mixed> $responseBody
     */
    protected function chatBatchResult(WorkflowSubjectInterface $subject, array $responseBody): TaskResult
    {
        $choice = $responseBody['choices'][0] ?? null;
        if (($choice['finish_reason'] ?? null) !== 'stop' || !is_string($choice['message']['content'] ?? null)) {
            throw new \UnexpectedValueException(sprintf('%s: incomplete batch response (finish_reason %s).', static::class, $choice['finish_reason'] ?? 'none'));
        }
        [$systemPrompt, $userPrompt] = $this->buildPrompts($subject);
        $usage = $responseBody['usage'] ?? [];

        return $this->taskResultFromData(
            $this->normalizeContent($choice['message']['content']),
            ['prompt' => $usage['prompt_tokens'] ?? null, 'completion' => $usage['completion_tokens'] ?? null, 'total' => $usage['total_tokens'] ?? null],
            $systemPrompt,
            $userPrompt,
            $responseBody['model'] ?? null,
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
    /**
     * The item's catalogue facts -- who made it, when, where, and its own caption -- as the
     * producer asserted them (mediary passes its source claims in the task context). They are
     * the "known" half of an observation: sourced, NOT file EXIF (a scanned negative's EXIF is
     * about the scan). Tasks show them to the model to ground its reading, never to override
     * what is visible.
     *
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    protected function knownFacts(array $context): array
    {
        $join = static fn (mixed $v): ?string => is_array($v)
            ? (implode('; ', array_filter(array_map('strval', $v), static fn (string $s): bool => trim($s) !== '')) ?: null)
            : (is_scalar($v) && trim((string) $v) !== '' ? (string) $v : null);

        return array_filter([
            'title'       => $join($context['title'] ?? null),
            'caption'     => $join($context['caption'] ?? null),
            'description' => $join($context['description'] ?? null),
            'date'        => $join($context['date'] ?? null),
            'creator'     => $join($context['creator'] ?? null),
            'collection'  => $join($context['collection'] ?? null),
            // Sourced place of capture -- grounds sign/word transcription toward the location's
            // language (e.g. Hungarian, not Cyrillic, for a Hungarian sign).
            'location'    => $join($context['place'] ?? null)
                ?? (trim(implode(', ', array_filter([$context['city'] ?? null, $context['country'] ?? null]))) ?: null),
        ], static fn (?string $v): bool => $v !== null);
    }

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

        if (is_array($decoded)) {
            return $decoded;
        }

        // A model that returns a bare JSON string (e.g. htr_annotate emits a quoted "<hw>…</hw>")
        // decodes to a string — use the DECODED text, not the quoted/escaped original, so callers
        // get clean content (no literal \n / \/ leaking into claims).
        return ['raw' => is_string($decoded) ? $decoded : (string) $content];
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
