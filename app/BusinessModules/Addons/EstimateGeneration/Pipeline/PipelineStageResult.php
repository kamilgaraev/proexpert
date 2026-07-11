<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class PipelineStageResult
{
    /**
     * @param  array<string, bool|float|int|string|null>  $metrics
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ProcessingStage $stage,
        public string $outputVersion,
        public array $metrics,
        public array $warnings = [],
    ) {
        if (trim($outputVersion) === '' || preg_match('/[\x00-\x1F\x7F]/', $outputVersion) === 1) {
            throw new InvalidArgumentException('Pipeline output version must be a non-empty safe string.');
        }

        foreach ($metrics as $name => $value) {
            if (! is_string($name) || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Pipeline metrics must contain named scalar values.');
            }
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning)) {
                throw new InvalidArgumentException('Pipeline warnings must contain strings.');
            }
        }
    }
}
