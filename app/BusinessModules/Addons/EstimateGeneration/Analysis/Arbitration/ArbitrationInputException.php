<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use InvalidArgumentException;

final class ArbitrationInputException extends InvalidArgumentException
{
    public function __construct(public readonly string $safeCode)
    {
        if (! in_array($safeCode, [
            'arbitration_requires_independent_observers',
            'arbitration_claims_missing',
        ], true)) {
            throw new InvalidArgumentException('arbitration_input_failure_code_invalid');
        }

        parent::__construct($safeCode);
    }
}
