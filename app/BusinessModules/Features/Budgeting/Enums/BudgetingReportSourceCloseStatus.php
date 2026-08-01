<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Enums;

enum BudgetingReportSourceCloseStatus: string
{
    case APPROVED = 'approved';
    case RESTATED = 'restated';
    case EXPIRED = 'expired';

    public function isAvailableForReporting(): bool
    {
        return $this === self::APPROVED;
    }

    public function canTransitionTo(self $next): bool
    {
        return $this === self::APPROVED && in_array($next, [self::RESTATED, self::EXPIRED], true);
    }
}
