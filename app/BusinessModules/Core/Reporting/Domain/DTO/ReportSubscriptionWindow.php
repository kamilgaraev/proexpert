<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;

final readonly class ReportSubscriptionWindow
{
    public function __construct(public ?string $cursor, public int $limit, public ?ReportSubscriptionStatus $status)
    {
        if ($limit < 1 || $limit > 100) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => ['limit']]);
        }
    }
}
