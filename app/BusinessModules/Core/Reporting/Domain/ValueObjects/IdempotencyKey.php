<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\ValueObjects;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;

final readonly class IdempotencyKey
{
    public string $hash;

    public function __construct(public string $value)
    {
        if (strlen($value) < 8 || strlen($value) > 128 || preg_match('/^[\x20-\x7E]+$/', $value) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_IDEMPOTENCY_KEY_INVALID);
        }

        $this->hash = hash('sha256', $value);
    }
}
