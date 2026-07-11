<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DomainException;

final class DuplicatePipelineStage extends DomainException
{
    public static function for(ProcessingStage $stage): self
    {
        return new self("Pipeline stage [{$stage->value}] is registered more than once.");
    }
}
