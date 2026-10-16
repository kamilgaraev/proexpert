<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use DateTimeImmutable;
use DateTimeZone;

final readonly class InventoryRiskPeriod
{
    public function resolve(ReportQuery $query): array
    {
        $start = $query->filters->values['period_start'] ?? null;
        $end = $query->filters->values['period_end'] ?? null;
        $periodStart = is_string($start) ? DateTimeImmutable::createFromFormat('!Y-m-d', $start) : false;
        $periodEnd = is_string($end) ? DateTimeImmutable::createFromFormat('!Y-m-d', $end) : false;
        if ($periodStart === false || $periodEnd === false
            || $periodStart->format('Y-m-d') !== $start
            || $periodEnd->format('Y-m-d') !== $end
            || $periodStart > $periodEnd) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                ['fields' => ['filters']],
            );
        }

        return [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')];
    }

    public function cutoff(ReportQuery $query): DateTimeImmutable
    {
        [, $end] = $this->resolve($query);
        $utc = new DateTimeZone('UTC');
        $periodEnd = (new DateTimeImmutable($end.' 23:59:59.999999', $query->scope->timezone))->setTimezone($utc);

        return ($query->asOf < $periodEnd ? $query->asOf : $periodEnd)->setTimezone($utc);
    }
}
