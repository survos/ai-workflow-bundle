<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\ObservationTaskInterface;
use Survos\ClaimsBundle\Entity\Claim;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tesseract OCR via the free, self-hosted ai-tools service — for clean typewritten
 * print and text-only documents. (Replaces the previous misnamed task that secretly
 * routed to OpenAI gpt-4o; this one is genuinely Tesseract and needs no API key.)
 */
#[AsTask('Tesseract OCR (free, self-hosted via ai-tools) — for clean typewritten print and text-only documents.', self::class, produces: ['ai:ocrText'], samples: ['https://s3.amazonaws.com/pastperfectonline/images/museum_986/006/20100110001.jpg'])]
final class OcrTask extends AbstractAiToolsTask implements ImageTaskInterface, ObservationTaskInterface
{
    public const string TASK = 'ocr_tesseract';

    public function __construct(
        #[Autowire(service: 'ai.platform.openresponses.ai_tools')]
        PlatformInterface $platform,
    ) {
        parent::__construct($platform);
    }

    protected function model(): string
    {
        return 'tesseract';
    }

    protected function platformName(): string
    {
        return 'ai-tools';
    }

    protected function claimPredicate(): ?string
    {
        return Claim::PRED_OCR_TEXT;
    }
}
