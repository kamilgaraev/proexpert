<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class UpdateReportSubscriptionData
{
    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes)
    {
        $allowed = [
            'frequency',
            'weekday',
            'day_of_month',
            'local_time',
            'timezone',
            'period_policy',
            'format',
            'saved_view_id',
        ];

        if ($changes === [] || array_diff(array_keys($changes), $allowed) !== []) {
            throw new InvalidArgumentException('report_subscription_update_invalid');
        }
    }
}
