<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions;

use RuntimeException;

final class ImportSessionStateException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly string $translationKey, public readonly int $httpStatus = 409)
    {
        parent::__construct($translationKey);
    }
}
