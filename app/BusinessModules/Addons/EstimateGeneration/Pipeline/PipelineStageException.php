<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use LogicException;

final class PipelineStageException extends LogicException
{
    public function __construct(
        public readonly FailureCategory $category,
        public readonly string $safeCode,
    ) {
        parent::__construct($safeCode);
    }
}
