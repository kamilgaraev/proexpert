<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Enums;

enum QualityDefectSeverityEnum: string
{
    case MINOR = 'minor';
    case MAJOR = 'major';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return trans_message("quality_control.severities.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::MINOR => 'yellow',
            self::MAJOR => 'orange',
            self::CRITICAL => 'red',
        };
    }
}
