<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface PublishDraftOnce
{
    public function publish(
        string $sessionId,
        string $pipelineVersion,
        string $artifactHash,
    ): DraftPublicationResult;
}
