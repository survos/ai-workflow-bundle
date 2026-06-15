<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\AiWorkflowBundle\Task\AbstractCapabilityTask;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * Base for free, self-hosted vision/OCR tasks served by ai-tools (OpenAI Responses API).
 *
 * ai-tools returns its result already as claims — `{"claims":[{"predicate","value"}]}` in the
 * Responses output text — so these are pure transducers: send the image URL through the
 * configured `openresponses` platform, map the returned claims. No API key, no hand-rolled HTTP;
 * the endpoint is configured once as a platform and every model (tesseract/florence-2/auto) is
 * just a `model()` away. This is the "configurable via the agents/tasks system, not one-offs" path.
 *
 * @author Tac Tacelosky <tacman@gmail.com>
 */
abstract class AbstractAiToolsTask extends AbstractCapabilityTask
{
    /** Map ai-tools claim predicates onto the keys TaskClaimMapper understands. */
    private const PREDICATE_MAP = [
        'observe:text' => 'text',
        'observe:caption' => 'title',
        'observe:description' => 'description',
        'observe:keywords' => 'keywords',
    ];

    public function inputKind(): string
    {
        return 'image';
    }

    protected function buildInput(WorkflowSubjectInterface $subject, array $inputs, array $context): object
    {
        return new MessageBag(Message::ofUser(new ImageUrl((string) $inputs['image_url'])));
    }

    protected function mapResult(DeferredResult $result, array $inputs, array $context): array
    {
        $text = $result->asText();
        $decoded = json_decode($text, true);
        $claims = \is_array($decoded) ? ($decoded['claims'] ?? []) : [];

        $data = [];
        foreach ($claims as $claim) {
            if (!\is_array($claim)) {
                continue;
            }
            $key = self::PREDICATE_MAP[(string) ($claim['predicate'] ?? '')] ?? null;
            if (null !== $key) {
                $data[$key] = $claim['value'] ?? null;
            }
        }

        if ([] === $data && '' !== trim($text)) {
            // Unrecognised envelope — keep the raw text so a claim is still produced.
            $data['text'] = $text;
        }

        return $data;
    }
}
