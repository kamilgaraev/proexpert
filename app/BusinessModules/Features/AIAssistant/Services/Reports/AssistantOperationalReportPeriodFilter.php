<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReportDateHelper;

final class AssistantOperationalReportPeriodFilter
{
    use ReportDateHelper;

    /**
     * @param array<string, mixed> $arguments
     * @return array{period: string, date_from: string|null, date_to: string|null, is_explicit: bool}
     */
    public function resolve(array $arguments): array
    {
        $period = $this->stringArgument($arguments['period'] ?? null);
        $dateFrom = $this->normalizeDateArgument($arguments['date_from'] ?? null);
        $dateTo = $this->normalizeDateArgument($arguments['date_to'] ?? null, true);

        if ($dateFrom !== null || $dateTo !== null) {
            return [
                'period' => $period ?? 'выбранный период',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'is_explicit' => true,
            ];
        }

        if ($period === null) {
            return [
                'period' => 'весь доступный период',
                'date_from' => null,
                'date_to' => null,
                'is_explicit' => false,
            ];
        }

        $dates = $this->extractPeriod($period);

        return [
            'period' => $period,
            'date_from' => $dates['date_from'],
            'date_to' => $dates['date_to'],
            'is_explicit' => true,
        ];
    }

    private function stringArgument(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
