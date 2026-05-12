<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Contract;

interface AiThumbnailProviderInterface
{
    /**
     * Return a URL suitable for AI vision tasks: ~512px, JPEG.
     * Falls back to the full-resolution URL if no small variant is available —
     * the workflow will still work, just at higher cost.
     */
    public function getAiSmallUrl(): string;
}
