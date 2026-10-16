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

        return [
            'project budget without currency' => ['budget_amount', 'number', ['budget_amount'], ['count']],
            'ordinary currency does not replace budget currency' => ['budget_amount', 'number', ['budget_amount', 'currency'], ['count']],
            'procurement budget with its currency' => ['budget_amount', 'number', ['budget_amount', 'budget_currency'], $all],
            'contract total with its currency' => ['total_amount', 'number', ['total_amount', 'currency'], $all],
            'budget currency does not replace amount currency' => ['total_amount', 'number', ['total_amount', 'budget_currency'], ['count']],
            'material quantities with units' => ['quantity', 'number', ['quantity', 'material_unit'], $all],
            'quantity without units' => ['quantity', 'number', ['quantity'], ['count']],
            'price cannot be added' => ['unit_price', 'number', ['unit_price', 'currency'], ['count', 'avg', 'min', 'max']],
            'identifier cannot be added' => ['project_id', 'number', ['project_id'], ['count']],
            'dimensionless labor hours' => ['hours_worked', 'number', ['hours_worked'], $all],
        ];
    }
}
