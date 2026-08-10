<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

final readonly class DraftPublicationResult
{
    public function __construct(
        public int $estimateId,
        public bool $created,
        public string $sessionId,
        public string $pipelineVersion,
        public string $artifactHash,
    ) {}

    public function idempotencyKey(): string
    {
        return hash('sha256', implode('|', [
            $this->sessionId,
            $this->pipelineVersion,
            $this->artifactHash,
        ]));
    }
}
