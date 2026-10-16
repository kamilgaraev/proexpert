<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final class AssistantCustomReportMeasure
{
    public static function aggregations(string $field, string $type, array $fields): array
    {
        if ($type !== 'number' || preg_match('/(^id$|_id$|number$|version$|position_number)/', $field)) {
            return ['count'];
        }
        $dimension = self::dimension($field, $fields);
        if ($dimension !== null && ! isset($fields[$dimension])) {
            return ['count'];
        }

        return preg_match('/price|percent|rate/', $field) ? ['count', 'avg', 'min', 'max'] : ['count', 'sum', 'avg', 'min', 'max'];
    }

    public static function dimension(string $field, array $fields): ?string
    {
        return match (true) {
            str_contains($field, 'budget') => 'budget_currency',
            (bool) preg_match('/amount|price|cost/', $field) => 'currency',
            (bool) preg_match('/quantity|volume/', $field) => isset($fields['material_unit']) ? 'material_unit' : (isset($fields['unit_code']) ? 'unit_code' : 'unit'),
            default => null,
        };
    }
}
