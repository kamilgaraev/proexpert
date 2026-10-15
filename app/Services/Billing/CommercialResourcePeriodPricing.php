<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CommercialBillingPeriod;
use Carbon\CarbonImmutable;

final class CommercialResourcePeriodPricing
{
    public static function apply(array $resources, array $quote): array
    {
        $term = CommercialBillingPeriod::from($quote['billing_period']);
        $start = CarbonImmutable::instance($quote['period_start_at']);
        $end = CarbonImmutable::instance($quote['period_end_at']);
        $duration = max(1, (int) $start->diffInSeconds($end));
        $remaining = max(0, (int) CarbonImmutable::instance(now())->diffInSeconds($end, false));
        if ($quote['current_package_slugs'] === [] || $quote['renew_subscription']) {
            $remaining = $duration;
        }
        $resources['monthly_amount_minor'] = (int) $resources['amount_minor'];
        $resources['amount_minor'] = 0;
        foreach ($resources['items'] as &$item) {
            $base = (int) $item['amount_minor'] * $term->months();
            $item['amount_minor'] = intdiv($base * $remaining + intdiv($duration, 2), $duration);
            $item['amount'] = number_format($item['amount_minor'] / 100, 2, '.', '');
            $resources['amount_minor'] += $item['amount_minor'];
        }
        unset($item);
        $resources['amount'] = number_format($resources['amount_minor'] / 100, 2, '.', '');

        return $resources;
    }
}
