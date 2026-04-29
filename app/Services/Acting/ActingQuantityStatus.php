<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Models\ContractPerformanceAct;

final class ActingQuantityStatus
{
    public static function releasedStatuses(): array
    {
        return [
            ContractPerformanceAct::STATUS_REJECTED,
            'cancelled',
            'canceled',
        ];
    }

    public static function approvedStatuses(): array
    {
        return [
            ContractPerformanceAct::STATUS_APPROVED,
            ContractPerformanceAct::STATUS_SIGNED,
        ];
    }

    public static function isReleased(?ContractPerformanceAct $act): bool
    {
        return in_array((string) $act?->status, self::releasedStatuses(), true);
    }

    public static function isApproved(?ContractPerformanceAct $act): bool
    {
        return (bool) $act?->is_approved
            || in_array((string) $act?->status, self::approvedStatuses(), true);
    }
}
