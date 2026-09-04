<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Exceptions;

use DomainException;

final class EstimateStructureImmutableException extends DomainException
{
    public function __construct()
    {
        parent::__construct('estimate_structure_immutable');
    }
}
