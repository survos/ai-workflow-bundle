<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Service\RunMeta;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\ObservationTaskInterface;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TaskNameTrait;
use Survos\AiWorkflowBundle\Task\TaskResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsTask('Dense-print OCR via Mistral — for typed/printed text, newspapers, tables, and certificates.', self::class, produces: ['ai:ocrText', 'ai:layoutBlock'], samples: ['https://s3.amazonaws.com/pastperfectonline/images/museum_986/006/20100110001.jpg'])]
final class OcrMistralTask implements TaskInterface, ImageTaskInterface, ObservationTaskInterface
{
    use TaskNameTrait;

    public const string TASK = 'ocr_mistral';

    /**
     * Pinned, not `mistral-ocr-latest`: OCR 4.x returns native paragraph-level blocks (type +
     * bbox + confidence), and an alias silently changing model underneath a corpus changes its
     * text. Override per call with $context['model'].
     */
    public const string MODEL = 'mistral-ocr-4-1';

    /**
     * Local images wider than this are downscaled before upload. 3000px was too small for
     * newspaper broadsheets: small type fell below legibility and the model filled the gaps with
     * invented text. Override with $context['max_image_width'].
     */
    private const int MAX_IMAGE_WIDTH = 6000;

    /**
     * Native OCR 4.x block type → the layout_blocks vocabulary consumers already speak
     * (folio's layout renderer, ssai). The original type is kept as `nativeType`.
     */
    private const array NATIVE_TYPES = [
        'text'       => 'paragraph',
        'caption'    => 'caption',
        'image'      => 'image',
        'table'      => 'table',
        'list'       => 'paragraph',
        'header'     => 'header',
        'footer'     => 'footer',
        'aside_text' => 'aside',
        'equation'   => 'other',
        'signature'  => 'other',
    ];

    private const LAYOUT_SCHEMA = [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'document_layout',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'blocks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['type', 'text', 'bbox'],
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['headline', 'subheadline', 'column', 'caption', 'byline', 'paragraph', 'image', 'advertisement', 'other']],
                                'text' => ['type' => 'string'],
                                'bbox' => [
                                    'type' => 'object',
                                    'required' => ['x', 'y', 'width', 'height'],
                                    'properties' => [
                                        'x' => ['type' => 'number'],
                                        'y' => ['type' => 'number'],
                                        'width' => ['type' => 'number'],
                                        'height' => ['type' => 'number'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TaskClaimMapper $claimMapper,
        #[Autowire('%env(MISTRAL_API_KEY)%')]
        private readonly string $mistralApiKey,
    ) {}

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        return $subject instanceof ImageSubjectInterface && $subject->getWorkflowImageUrl() !== null;
    }

    public function getMeta(): array
    {
        return [
            'agent'    => self::MODEL,
            'platform' => 'mistral',
            'model'    => self::MODEL,
        ];
    }

    public function run(WorkflowSubjectInterface $subject): TaskResult
    {
        if (!$subject instanceof ImageSubjectInterface || ($url = $subject->getWorkflowImageUrl()) === null) {
            throw new \RuntimeException('OcrMistralTask requires an ImageSubjectInterface with an image URL.');
        }

        $context = $subject instanceof ContextSubjectInterface ? $subject->getWorkflowContext() : [];
        $data    = $this->ocr($url, $context);

        return new TaskResult(
            claims: $this->claimMapper->map($data, Claim::PRED_OCR_TEXT),
            meta: new RunMeta(
                model: (string) ($data['model'] ?? self::MODEL),
                response: $data,
            ),
        );
    }

    /**
     * Run Mistral OCR for a single image/PDF URL and return the normalized data
     * blob (text, layout_blocks, image_blocks, per-page markdown, raw_response).
     *
     * Subject-free entry point so callers (e.g. the S3 sidecar cache) can run
     * OCR from a bare URL without a workflow subject. {@see run()} delegates here.
     *
     * Layout comes from OCR 4.x's native blocks (type, bbox, confidence) — cheap and measured,
     * not an LLM's guess. The old document_annotation layout schema is opt-in for models that
     * predate native blocks.
     *
     * @param array<string, mixed> $context optional hints:
     *   - max_pages: int, first N pages of a PDF
     *   - model: string, override {@see self::MODEL}
     *   - max_image_width: int, downscale local images wider than this
     *   - layout_annotation: bool, also request the LLM layout annotation (legacy)
     *   - document_annotation_format: array, a caller's own JSON schema (e.g. masthead metadata)
     *
     * @return array<string, mixed>
     */
    public function ocr(string $url, array $context = []): array
    {
        $maxPages = (int) ($context['max_pages'] ?? 0);
        $isPdf    = $this->isPdf($url);

        $payload = [
            'model'                         => (string) ($context['model'] ?? self::MODEL),
            'document'                      => $this->documentPayload($url, $isPdf, (int) ($context['max_image_width'] ?? self::MAX_IMAGE_WIDTH)),
            'include_image_base64'          => false,
            'include_blocks'                => true,
            'confidence_scores_granularity' => 'block',
        ];
        if (isset($context['document_annotation_format']) && is_array($context['document_annotation_format'])) {
            $payload['document_annotation_format'] = $context['document_annotation_format'];
        } elseif (($context['layout_annotation'] ?? false) === true) {
            $payload['document_annotation_format'] = self::LAYOUT_SCHEMA;
        }

        if ($isPdf && $maxPages > 0) {
            $payload['pages'] = range(0, $maxPages - 1);
        }

        $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/ocr', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mistralApiKey,
                'Content-Type'  => 'application/json',
            ],
            'json'    => $payload,
            'timeout' => 300,
        ]);

        return $this->normalizeResponse($response->toArray());
    }

    private function documentPayload(string $url, bool $isPdf, int $maxImageWidth = self::MAX_IMAGE_WIDTH): array
    {
        if (!str_starts_with($url, 'file://')) {
            return $isPdf
                ? ['type' => 'document_url', 'document_url' => $url]
                : ['type' => 'image_url',    'image_url'    => $url];
        }

        $path   = substr($url, 7);
        $binary = $isPdf ? file_get_contents($path) : $this->resizeIfNeeded($path, $maxImageWidth);
        if ($binary === false) {
            throw new \RuntimeException(sprintf('Cannot read local %s: %s', $isPdf ? 'PDF' : 'image', $path));
        }

        return $isPdf
            ? ['type' => 'document_url', 'document_url' => 'data:application/pdf;base64,' . base64_encode($binary)]
            : ['type' => 'image_url',    'image_url'    => 'data:image/jpeg;base64,'      . base64_encode($binary)];
    }

    private function normalizeResponse(array $data): array
    {
        $pages    = $data['pages'] ?? [];
        $fullText = implode("\n\n", array_map(
            static fn (array $page): string => $page['markdown'] ?? '',
            $pages,
        ));

        // Native OCR 4.x blocks first; the LLM annotation only when no page carried native blocks
        // (an older model, or a caller that opted into layout_annotation on one).
        $layoutBlocks = [];
        foreach ($pages as $pageData) {
            foreach ($pageData['blocks'] ?? [] as $block) {
                if (is_array($block)) {
                    $layoutBlocks[] = $this->nativeBlock($block, (int) ($pageData['index'] ?? 0));
                }
            }
        }
        if ($layoutBlocks === []) {
            $topAnnotation = $data['document_annotation'] ?? null;
            if (is_string($topAnnotation) && isset($pages[0])) {
                $layoutBlocks = array_merge($layoutBlocks, $this->parseAnnotation($topAnnotation, $pages[0], 0));
            }
            foreach ($pages as $pageData) {
                $pageAnnotation = $pageData['document_annotation'] ?? null;
                if (is_string($pageAnnotation)) {
                    $layoutBlocks = array_merge($layoutBlocks, $this->parseAnnotation($pageAnnotation, $pageData, $pageData['index'] ?? 0));
                }
            }
        }

        $imageBlocks = [];
        foreach ($pages as $page) {
            foreach ($page['images'] ?? [] as $img) {
                $imageBlocks[] = array_filter([
                    'page'           => $page['index'] ?? 0,
                    'id'             => $img['id'] ?? null,
                    'top_left_x'     => $img['top_left_x'] ?? null,
                    'top_left_y'     => $img['top_left_y'] ?? null,
                    'bottom_right_x' => $img['bottom_right_x'] ?? null,
                    'bottom_right_y' => $img['bottom_right_y'] ?? null,
                    'annotation'     => $img['image_annotation'] ?? null,
                ], static fn (mixed $v): bool => $v !== null);
            }
        }

        foreach ($data['pages'] ?? [] as $pi => $page) {
            foreach ($page['images'] ?? [] as $ii => $img) {
                unset($data['pages'][$pi]['images'][$ii]['image_base64']);
            }
        }

        return [
            'model'         => $data['model'] ?? null,
            'text'          => trim($fullText),
            'language'      => null,
            'confidence'    => 'high',
            'layout_blocks' => $layoutBlocks,
            'image_blocks'  => $imageBlocks,
            'pages'         => array_map(static fn (array $p): array => [
                'index'      => $p['index']      ?? 0,
                'markdown'   => $p['markdown']   ?? '',
                'dimensions' => $p['dimensions'] ?? null,
                'tables'     => $p['tables']     ?? [],
                'header'     => $p['header']     ?? null,
                'footer'     => $p['footer']     ?? null,
                'confidence' => $p['confidence_scores']['average_page_confidence_score'] ?? null,
            ], $pages),
            'raw_response'  => $data,
        ];
    }

    /**
     * One native OCR 4.x block in the layout_blocks shape: {page, type, text, bbox{x,y,width,height}}
     * in the page's own pixel space (pages[].dimensions), plus nativeType and confidence.
     * A `title` becomes headline or subheadline by its markdown heading depth.
     *
     * @param array<string, mixed> $block a pages[].blocks[] entry
     *
     * @return array<string, mixed>
     */
    private function nativeBlock(array $block, int $pageIndex): array
    {
        $native = (string) ($block['type'] ?? 'text');
        $text   = (string) ($block['content'] ?? '');
        $type   = $native === 'title'
            ? (preg_match('/^\s*#{2,}\s/', $text) === 1 ? 'subheadline' : 'headline')
            : (self::NATIVE_TYPES[$native] ?? 'other');
        $x1 = (int) ($block['top_left_x'] ?? 0);
        $y1 = (int) ($block['top_left_y'] ?? 0);

        return array_filter([
            'page'       => $pageIndex,
            'type'       => $type,
            'nativeType' => $native,
            'text'       => $text,
            'bbox'       => [
                'x'      => $x1,
                'y'      => $y1,
                'width'  => max(0, (int) ($block['bottom_right_x'] ?? $x1) - $x1),
                'height' => max(0, (int) ($block['bottom_right_y'] ?? $y1) - $y1),
            ],
            'confidence' => $block['confidence_scores']['average_content_confidence_score'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    private function parseAnnotation(string $rawAnnotation, array $pageData, int $pageIndex): array
    {
        $parsed = json_decode($rawAnnotation, true);
        if (!is_array($parsed)) {
            return [];
        }

        $rawBlocks  = $parsed['blocks'] ?? [];
        $dims       = $pageData['dimensions'] ?? null;
        $imgW       = $dims['width']  ?? 0;
        $imgH       = $dims['height'] ?? 0;
        $renderMaxX = 0.0;
        $renderMaxY = 0.0;
        foreach ($rawBlocks as $block) {
            $renderMaxX = max($renderMaxX, (float) (($block['bbox']['x'] ?? 0) + ($block['bbox']['width']  ?? 0)));
            $renderMaxY = max($renderMaxY, (float) (($block['bbox']['y'] ?? 0) + ($block['bbox']['height'] ?? 0)));
        }
        $scaleX = ($renderMaxX > 0 && $imgW > 0) ? $imgW / $renderMaxX : 1.0;
        $scaleY = ($renderMaxY > 0 && $imgH > 0) ? $imgH / $renderMaxY : 1.0;

        $blocks = [];
        foreach ($rawBlocks as $block) {
            $bbox     = $block['bbox'] ?? [];
            $blocks[] = [
                'page' => $pageIndex,
                'type' => $block['type'] ?? 'other',
                'text' => $block['text'] ?? '',
                'bbox' => [
                    'x'      => (int) round(($bbox['x']      ?? 0) * $scaleX),
                    'y'      => (int) round(($bbox['y']      ?? 0) * $scaleY),
                    'width'  => (int) round(($bbox['width']  ?? 0) * $scaleX),
                    'height' => (int) round(($bbox['height'] ?? 0) * $scaleY),
                ],
            ];
        }

        return $blocks;
    }

    private function resizeIfNeeded(string $path, int $maxWidth): string
    {
        if (!extension_loaded('gd')) {
            $binary = file_get_contents($path);
            if ($binary === false) {
                throw new \RuntimeException("Cannot read local image: {$path}");
            }
            return $binary;
        }

        [$origW, $origH, $type] = getimagesize($path);
        if ($origW <= $maxWidth) {
            $binary = file_get_contents($path);
            if ($binary === false) {
                throw new \RuntimeException("Cannot read local image: {$path}");
            }
            return $binary;
        }

        $newW = $maxWidth;
        $newH = (int) round($origH * ($maxWidth / $origW));
        $src  = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default        => imagecreatefromjpeg($path),
        };
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, 88);
        imagedestroy($dst);

        return ob_get_clean();
    }

    private function isPdf(string $url): bool
    {
        return str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?: $url), '.pdf');
    }
}
