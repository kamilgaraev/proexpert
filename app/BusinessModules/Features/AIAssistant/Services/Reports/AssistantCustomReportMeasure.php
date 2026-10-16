<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;

final class AssistantCustomReportMeasure
{
    private const STOCK_QUANTITIES = ['opening_on_hand', 'closing_on_hand', 'receipts', 'issues', 'inbound_transfers',
        'outbound_transfers', 'returns', 'positive_adjustments', 'negative_adjustments', 'on_hand_delta', 'reserved_delta'];

    public static function aggregations(string $field, string $type, array $fields): array
    {
        if ($type !== 'number' || preg_match('/(^id$|_id$|number$|version$|position_number)/', $field)) {
            return ['count'];
        }
        $dimensions = self::dimensions($field, $fields);
        if (array_diff($dimensions, array_keys($fields)) !== []
            || ($dimensions === [] && ! preg_match('/(?:^|_)(?:count|hours?|days?|percent|rate|ratio|turnover)(?:_|$)/', $field))) {
            return ['count'];
        }

        return preg_match('/price|percent|rate|ratio|turnover|_per_|_days$/', $field) ? ['count', 'avg', 'min', 'max'] : ['count', 'sum', 'avg', 'min', 'max'];
    }

    public static function dimensions(string $field, array $fields): array
    {
        $dimensions = match (true) {
            str_contains($field, 'budget') => ['budget_currency'],
            (bool) preg_match('/amount|price|cost/', $field), in_array($field, AssistantExtendedDomainRegistry::values('monetaryFields'), true) => ['currency'],
            default => [],
        };
        if (preg_match('/quantity|volume|unit_price/', $field) || in_array($field, self::STOCK_QUANTITIES, true)) {
            $dimensions[] = isset($fields['material_unit']) ? 'material_unit' : (isset($fields['unit_code']) ? 'unit_code' : 'unit');
            if (isset($fields['unit_dimension'])) {
                $dimensions[] = 'unit_dimension';
            }
        }

        return $dimensions;
    }
}
