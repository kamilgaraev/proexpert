<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum PackageSubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Grace = 'grace';
    case ScheduledForRemoval = 'scheduled_for_removal';
    case Expired = 'expired';
    case Canceled = 'canceled';

    public static function periodAccessValues(): array
    {
        return [
            self::Active->value,
            self::Grace->value,
            self::ScheduledForRemoval->value,
        ];
    }
}
