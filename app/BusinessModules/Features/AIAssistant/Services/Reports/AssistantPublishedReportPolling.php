<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final class AssistantPublishedReportPolling
{
    public static function seconds(int $ageSeconds): int
    {
        return match (true) {
            $ageSeconds < 30 => 5,
            $ageSeconds < 120 => 15,
            $ageSeconds < 600 => 30,
            default => 60,
        };
    }
}
