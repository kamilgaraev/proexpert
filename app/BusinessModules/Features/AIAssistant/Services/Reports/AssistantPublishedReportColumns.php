<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final class AssistantPublishedReportColumns
{
    public static function exportable(string $reportCode, array $columns): array
    {
        if ($reportCode !== 'accepted_production_progress') {
            return $columns;
        }

        return array_values(array_filter($columns, static fn (array $column): bool => ! in_array($column['id'], ['row_key', 'drill'], true)));
    }
}
