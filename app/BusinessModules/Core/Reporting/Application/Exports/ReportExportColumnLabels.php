<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

final class ReportExportColumnLabels
{
    public static function resolve(string $reportCode, string $columnId, string $locale, array $column): string
    {
        $labels = $column['labels'] ?? null;
        $label = is_array($labels) && isset($labels[$locale]) ? $labels[$locale] : ($column['label'] ?? null);
        if (is_string($label) && trim($label) !== '') {
            return $label;
        }
        if ($reportCode === 'accepted_production_progress' && str_starts_with($locale, 'ru')) {
            $key = 'reports.accepted_production_columns.'.$columnId;
            $label = trans_message($key, [], 'ru');
            if (is_string($label) && $label !== $key && trim($label) !== '') {
                return $label;
            }
        }

        return $columnId;
    }
}
