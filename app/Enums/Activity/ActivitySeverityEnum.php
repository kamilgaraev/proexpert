<?php

declare(strict_types=1);

namespace App\Enums\Activity;

enum ActivitySeverityEnum: string
{
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';
}
