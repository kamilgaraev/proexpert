<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\DTO;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class CustomerSlaPauseWindow
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {
        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('customer_sla_pause_window_invalid');
        }
    }
}
