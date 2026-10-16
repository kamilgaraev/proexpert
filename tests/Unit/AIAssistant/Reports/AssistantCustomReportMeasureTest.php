<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportMeasure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantCustomReportMeasureTest extends TestCase
{
    #[DataProvider('measures')]
    public function test_published_aggregations_require_the_selected_measure_dimension(string $field, string $type, array $columns, array $expected): void
    {
        self::assertSame($expected, AssistantCustomReportMeasure::aggregations($field, $type, array_fill_keys($columns, true)));
    }

    public static function measures(): array
    {
        $all = ['count', 'sum', 'avg', 'min', 'max'];

        $cases = [
            'project budget without currency' => ['budget_amount', 'number', ['budget_amount'], ['count']],
            'ordinary currency does not replace budget currency' => ['budget_amount', 'number', ['budget_amount', 'currency'], ['count']],
            'procurement budget with its currency' => ['budget_amount', 'number', ['budget_amount', 'budget_currency'], $all],
            'contract total with its currency' => ['total_amount', 'number', ['total_amount', 'currency'], $all],
            'budget currency does not replace amount currency' => ['total_amount', 'number', ['total_amount', 'budget_currency'], ['count']],
            'material quantities with units' => ['quantity', 'number', ['quantity', 'material_unit'], $all],
            'quantity without units' => ['quantity', 'number', ['quantity'], ['count']],
            'price cannot be added' => ['unit_price', 'number', ['unit_price', 'currency', 'unit_code'], ['count', 'avg', 'min', 'max']],
            'identifier cannot be added' => ['project_id', 'number', ['project_id'], ['count']],
            'dimensionless labor hours' => ['hours_worked', 'number', ['hours_worked'], $all],
            'price without quantity unit' => ['unit_price_minor', 'number', ['unit_price_minor', 'currency'], ['count']],
            'unknown numeric measure' => ['unclassified_metric', 'number', ['unclassified_metric'], ['count']],
        ];
        foreach (['opening_on_hand', 'receipts', 'issues', 'inbound_transfers', 'outbound_transfers', 'returns',
            'positive_adjustments', 'negative_adjustments', 'closing_on_hand', 'on_hand_delta', 'reserved_delta',
            'reserved_quantity', 'available_quantity', 'consumption_quantity', 'recommended_order_quantity'] as $field) {
            $cases[$field.' without unit'] = [$field, 'number', [$field], ['count']];
            $cases[$field.' with composite unit'] = [$field, 'number', [$field, 'unit_dimension', 'unit_code'], $all];
        }
        foreach (['consumption_value_minor', 'on_hand_value_minor', 'cost_turnover', 'total_value', 'operating_cost_per_hour'] as $field) {
            $cases[$field.' without currency'] = [$field, 'number', [$field], ['count']];
        }

        return $cases;
    }
}
