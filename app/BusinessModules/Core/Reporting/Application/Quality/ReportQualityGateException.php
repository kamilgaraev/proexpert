<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Quality;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use RuntimeException;

final class ReportQualityGateException extends RuntimeException
{
    public function __construct(public readonly ReportQualityGateFailureCode $failureCode)
    {
        parent::__construct("quality-gate:{$failureCode->value}", 2);
    }

    public function exitCode(): int
    {
        return 2;
    }
}
