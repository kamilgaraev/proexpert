<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use Carbon\CarbonImmutable;
use DomainException;
use function trans_message;

final class WipForecastPeriodGuard
{
    /**
     * @param list<string> $lockedPeriods
     */
    public function assertWritablePeriod(string $period, array $lockedPeriods): void
    {
        $month = CarbonImmutable::parse($period . '-01')->format('Y-m');

        if (in_array($month, $lockedPeriods, true)) {
            throw new DomainException(trans_message('budgeting.wip_forecast.errors.closed_period_locked'));
        }
    }

    public function assertVersionIsWritablePeriod(BudgetPeriod $period): void
    {
        $lockedStatuses = [
            BudgetPeriodClosureService::STATUS_CLOSING,
            BudgetPeriodClosureService::STATUS_CLOSED,
            BudgetPeriodClosureService::STATUS_SOFT_CLOSED,
            BudgetPeriodClosureService::STATUS_ARCHIVED,
        ];

        if (in_array((string) $period->status, $lockedStatuses, true)) {
            throw new DomainException(trans_message('budgeting.wip_forecast.errors.closed_period_locked'));
        }
    }
}
