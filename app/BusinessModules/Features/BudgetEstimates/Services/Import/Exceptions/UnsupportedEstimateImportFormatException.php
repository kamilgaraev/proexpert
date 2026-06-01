<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions;

use RuntimeException;

use function trans_message;

final class UnsupportedEstimateImportFormatException extends RuntimeException
{
    public static function create(): self
    {
        return new self(trans_message('estimate.import_unsupported_format'));
    }
}
