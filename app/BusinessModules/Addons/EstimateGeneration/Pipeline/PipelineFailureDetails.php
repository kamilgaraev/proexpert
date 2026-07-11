<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use Throwable;

final readonly class PipelineFailureDetails
{
    private function __construct(
        public string $code,
        public string $fingerprint,
    ) {}

    public static function from(Throwable $error): self
    {
        return new self(
            'pipeline_stage_failed',
            hash('sha256', $error::class."\0".(string) $error->getCode()."\0".$error->getMessage()),
        );
    }
}
