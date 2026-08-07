<?php

declare(strict_types=1);

require_once __DIR__.'/../../../app/Enums/CurrencyCode.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthImmutableOwnerPayloadBuilder.php';

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthImmutableOwnerPayloadBuilder;

$sourceRefs = static fn (string $type, int $id): array => [['type' => $type, 'id' => $id]];

$payload = (new ProjectPortfolioHealthImmutableOwnerPayloadBuilder)->build([
    'project_margin' => [
        [
            'row_key' => 'margin-1',
            'project_id' => 10,
            'project_name' => 'Проект 10',
            'currency' => 'RUB',
            'actual_revenue_minor' => 10050,
            'actual_cost_minor' => 7025,
            'forecast_revenue_minor' => 15000,
            'forecast_cost_minor' => 9000,
            'source_refs' => $sourceRefs('approved_act', 101),
        ],
    ],
    'wip_completion_forecast' => [
        [
            'row_key' => 'wip-1',
            'project_id' => 10,
            'project_name' => 'Проект 10',
            'currency' => 'RUB',
            'ac_minor' => 4000,
            'wip_minor' => 1200,
            'ctc_minor' => 8000,
            'eac_minor' => 10000,
            'source_refs' => $sourceRefs('earned_value', 201),
        ],
        [
            'row_key' => 'wip-2',
            'project_id' => 10,
            'project_name' => 'Проект 10',
            'currency' => 'RUB',
            'ac_minor' => 1000,
            'wip_minor' => 300,
            'ctc_minor' => 2000,
            'eac_minor' => 2500,
            'source_refs' => $sourceRefs('actual_cost', 202),
        ],
    ],
    'budget_plan_fact' => [
        [
            'row_key' => 'plan-1',
            'project_id' => 10,
            'project_name' => 'Проект 10',
            'currency' => 'RUB',
            'variance_minor' => -250,
            'risk' => 'medium',
            'source_refs' => $sourceRefs('budget_line', 301),
        ],
        [
            'row_key' => 'plan-2',
            'project_id' => 10,
            'project_name' => 'Проект 10',
            'currency' => 'RUB',
            'variance_minor' => 100,
            'risk' => 'high',
            'source_refs' => $sourceRefs('budget_line', 302),
        ],
    ],
]);

assert($payload['project_margin']['rows'][0]['actual']['revenue'] === '100.50');
assert($payload['project_margin']['rows'][0]['actual']['cost'] === '70.25');
assert($payload['project_margin']['rows'][0]['forecast']['gross_margin'] === '60.00');
assert($payload['wip_completion_forecast']['rows'][0]['metrics']['wip_total'] === '15.00');
assert($payload['wip_completion_forecast']['rows'][0]['metrics']['ftc'] === '75.00');
assert($payload['wip_completion_forecast']['rows'][0]['metrics']['eac'] === '125.00');
assert($payload['wip_completion_forecast']['rows'][0]['metrics']['ctc'] === '100.00');
assert($payload['wip_completion_forecast']['rows'][0]['metrics']['forecast_gross_margin'] === '60.00');
assert($payload['budget_plan_fact']['rows'][0]['variance_amount'] === '-1.50');
assert($payload['budget_plan_fact']['rows'][0]['risk_level'] === 'high');
assert(count($payload['wip_completion_forecast']['rows'][0]['source_refs']) === 2);

$failedClosed = false;
try {
    (new ProjectPortfolioHealthImmutableOwnerPayloadBuilder)->build([
        'project_margin' => [],
        'wip_completion_forecast' => [],
        'budget_plan_fact' => [],
    ]);
} catch (InvalidArgumentException) {
    $failedClosed = true;
}
assert($failedClosed);

$persistedShape = [
    'project_margin' => [[...$payload['project_margin']['rows'][0],
        'row_key' => 'persisted-margin',
        'project_id' => 10,
        'project_name' => 'Проект 10',
        'actual_revenue_minor' => 10050,
        'actual_cost_minor' => 7025,
        'forecast_revenue_minor' => 15000,
        'forecast_cost_minor' => 9000,
        'source_refs' => [],
    ]],
    'wip_completion_forecast' => [[
        'row_key' => 'persisted-wip',
        'project_id' => 10,
        'project_name' => 'Проект 10',
        'currency' => 'RUB',
        'ac_minor' => 5000,
        'wip_minor' => 1500,
        'ctc_minor' => 10000,
        'eac_minor' => 12500,
        'source_refs' => [],
    ]],
    'budget_plan_fact' => [[
        'row_key' => 'persisted-plan-fact',
        'project_id' => 10,
        'project_name' => 'Проект 10',
        'currency' => 'RUB',
        'variance_minor' => -150,
        'risk' => 'high',
        'source_refs' => [],
    ]],
];
$persistedPayload = (new ProjectPortfolioHealthImmutableOwnerPayloadBuilder)->build($persistedShape);
assert($persistedPayload['project_margin']['rows'][0]['source_refs'] === []);
assert($persistedPayload['wip_completion_forecast']['rows'][0]['source_refs'] === []);
assert($persistedPayload['budget_plan_fact']['rows'][0]['source_refs'] === []);

echo "project_portfolio_health_immutable_owner_payload_harness: ok\n";
