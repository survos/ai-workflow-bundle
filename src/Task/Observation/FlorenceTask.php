<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Task\Observation;

use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\ObservationTaskInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Florence-2 vision via the free, self-hosted ai-tools service — returns a caption,
 * a description, OCR text and keywords for an image in one pass. No API key.
 */
#[AsTask('Florence-2 vision (free, self-hosted via ai-tools) — caption, description, OCR text and keywords for an image.', self::class, produces: ['ai:ocrText', 'dcterms:title', 'dcterms:description', 'dcterms:subject'], samples: ['https://iiif.digitalcommonwealth.org/iiif/2/commonwealth:pz50hp570/full/,1200/0/default.jpg'])]
final class FlorenceTask extends AbstractAiToolsTask implements ImageTaskInterface, ObservationTaskInterface
{
    public const string TASK = 'florence';

    public function __construct(
        #[Autowire(service: 'ai.platform.openresponses.ai_tools')]
        PlatformInterface $platform,
    ) {
        parent::__construct($platform);
    }

    protected function model(): string
    {
        return 'florence-2';
    }

    protected function platformName(): string
    {
        return 'ai-tools';
    }
}
