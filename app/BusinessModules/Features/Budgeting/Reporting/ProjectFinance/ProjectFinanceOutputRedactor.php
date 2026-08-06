<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

final readonly class ProjectFinanceOutputRedactor
{
    private const SENSITIVE_TOTAL_FIELDS = [
        'wip_completion_forecast' => [
            'bac_minor',
            'pv_minor',
            'ev_minor',
            'ac_minor',
            'ctc_minor',
            'eac_minor',
            'forecast_variance_minor',
        ],
    ];

    public function totals(string $reportCode, array $totals, bool $canViewSensitive): array
    {
        if ($canViewSensitive) {
            return $totals;
        }

        $fields = self::SENSITIVE_TOTAL_FIELDS[$reportCode] ?? [];
        if ($fields === []) {
            return $totals;
        }

        return $this->redact($totals, $fields);
    }

    private function redact(array $value, array $fields): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $fields, true)) {
                unset($value[$key]);
                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->redact($item, $fields);
            }
        }

        return $value;
    }
}
