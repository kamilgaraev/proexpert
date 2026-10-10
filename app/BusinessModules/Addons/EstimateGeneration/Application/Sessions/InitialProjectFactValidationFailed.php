<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use InvalidArgumentException;

final class InitialProjectFactValidationFailed extends InvalidArgumentException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('initial_parameter_number_invalid');
    }
}
