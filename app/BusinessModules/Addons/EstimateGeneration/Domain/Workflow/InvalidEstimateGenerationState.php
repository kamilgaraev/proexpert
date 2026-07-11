<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

use DomainException;

final class InvalidEstimateGenerationState extends DomainException
{
    public function __construct(EstimateGenerationStatus $status, string $operation)
    {
        parent::__construct(sprintf('Operation "%s" is not allowed for status "%s".', $operation, $status->value));
    }
}
