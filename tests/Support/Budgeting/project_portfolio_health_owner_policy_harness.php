<?php

declare(strict_types=1);

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthOwnerSourcePolicy;

require_once __DIR__.'/../../../app/Enums/CurrencyCode.php';
require_once __DIR__.'/../../../app/BusinessModules/Core/Reporting/Support/CanonicalJson.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/DTOs/PlanFactReportFilters.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/DTOs/ProjectMarginReportFilters.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthOwnerSourcePolicy.php';

$policy = new ProjectPortfolioHealthOwnerSourcePolicy;
$request = [
    'scope_project_ids' => [10, 20],
    'project_ids' => [10],
    'currencies' => ['RUB'],
    'responsibility_center_ids' => [30],
    'responsibility_center_uuids' => ['11111111-1111-4111-8111-111111111111'],
    'counterparty_ids' => [40],
];
$source = [
    'project_id' => null,
    'currency' => 'RUB',
    'filters' => [
        'project_ids' => [10],
        'currencies' => ['RUB'],
        'responsibility_center_ids' => [30],
        'counterparty_ids' => [40],
    ],
    'row_project_ids' => [10],
    'row_currencies' => ['RUB'],
];

assert($policy->matches($request, $source));
assert(! $policy->matches($request, [...$source, 'currency' => 'USD']));
assert(! $policy->matches($request, [...$source, 'filters' => [...$source['filters'], 'counterparty_ids' => [41]]]));

$snapshotRequest = [
    ...$request,
    'scope_project_ids' => [10],
    'project_ids' => [10],
    'organization_id' => 38,
    'kind' => 'project_margin',
    'epm_scope' => 'project_margin',
    'scope_hash' => str_repeat('1', 64),
    'period_from' => '2026-08-01',
    'period_to' => '2026-08-04',
    'as_of' => '2026-08-04T00:00:00+00:00',
    'as_of_date' => '2026-08-04',
    'formula' => 'margin-v1',
    'schema' => 'project_margin_source_v1',
    'parent_formula' => 'budgeting_epm_data_mart_v1',
    'expected_definition_hash' => str_repeat('2', 64),
    'expected_query_hash' => str_repeat('3', 64),
];
$snapshotSource = [
    ...$source,
    'project_id' => 10,
    'filters' => [
        'close_id' => '01K1CLOSE00000000000000000',
        'scenario_uuid' => 'scenario-v1',
        'budget_version_uuid' => 'budget-v1',
        'responsibility_center_id' => '11111111-1111-4111-8111-111111111111',
        'counterparty_id' => 40,
        'group_by' => ['project', 'currency'],
    ],
    'organization_id' => 38,
    'report_code' => 'project_margin',
    'scope_hash' => str_repeat('1', 64),
    'definition_hash' => str_repeat('2', 64),
    'query_hash' => str_repeat('3', 64),
    'source_hash' => str_repeat('4', 64),
    'source_snapshot_kind' => 'budgeting_epm_data_mart',
    'source_snapshot_id' => '01K1SOURCE00000000000000000',
    'source_snapshot_hash' => str_repeat('5', 64),
    'formula' => 'margin-v1',
    'schema' => 'project_margin_source_v1',
    'quality_status' => 'complete',
    'coverage_numerator' => 1,
    'coverage_denominator' => 1,
    'row_count' => 1,
    'rows_count' => 1,
    'row_organization_ids' => [38],
    'row_report_codes' => ['project_margin'],
    'period_from' => '2026-08-01',
    'period_to' => '2026-08-04',
    'as_of' => '2026-08-04T00:00:00+00:00',
    'generated_at' => '2026-08-03T23:00:00+00:00',
    'stale_at' => '2026-08-05T00:00:00+00:00',
    'parent_uuid' => '01K1SOURCE00000000000000000',
    'parent_organization_id' => 38,
    'parent_report_scope' => 'project_margin',
    'parent_scope_hash_valid' => true,
    'parent_status' => 'succeeded',
    'parent_superseded_at' => null,
    'parent_formula' => 'budgeting_epm_data_mart_v1',
    'parent_source_hash' => str_repeat('5', 64),
    'parent_generated_at' => '2026-08-03T23:00:00+00:00',
    'parent_stale_at' => '2026-08-05T00:00:00+00:00',
    'parent_period_from' => '2026-08-01',
    'parent_period_to' => '2026-08-04',
    'parent_as_of_date' => '2026-08-04',
    'budget_version_id' => null,
    'forecast_version_id' => null,
    'closure_hash' => null,
];
assert($policy->accepts($snapshotRequest, $snapshotSource));
assert(! $policy->accepts($snapshotRequest, [...$snapshotSource, 'stale_at' => $snapshotRequest['as_of']]));
assert(! $policy->accepts($snapshotRequest, [...$snapshotSource, 'coverage_denominator' => 2]));
assert(! $policy->accepts($snapshotRequest, [...$snapshotSource, 'organization_id' => 39]));
assert(! $policy->accepts($snapshotRequest, [...$snapshotSource, 'parent_report_scope' => 'plan_fact']));
assert(! $policy->accepts($snapshotRequest, [
    ...$snapshotSource,
    'definition_hash' => str_repeat('6', 64),
]));
assert(! $policy->accepts($snapshotRequest, [
    ...$snapshotSource,
    'query_hash' => str_repeat('7', 64),
]));
assert(! $policy->accepts($snapshotRequest, [
    ...$snapshotSource,
    'filters' => [...$snapshotSource['filters'], 'budget_article_id' => 99],
]));
assert(! $policy->accepts($snapshotRequest, [...$snapshotSource, 'budget_version_id' => 12]));
assert(! $policy->accepts($snapshotRequest, [...$snapshotSource, 'closure_hash' => str_repeat('5', 64)]));
$cohort = $policy->cohortHash($snapshotRequest, $snapshotSource);
$foreignCohort = $policy->cohortHash($snapshotRequest, [
    ...$snapshotSource,
    'filters' => [...$snapshotSource['filters'], 'scenario_uuid' => 'scenario-v2'],
]);
assert(is_string($cohort));
assert(is_string($foreignCohort));
assert($cohort !== $foreignCohort);

$planRequest = [
    ...$snapshotRequest,
    'kind' => 'budget_plan_fact',
    'epm_scope' => 'plan_fact',
    'formula' => 'plan-fact-v1',
    'schema' => 'budget_plan_fact_source_v1',
];
$planSource = [
    ...$snapshotSource,
    'report_code' => 'budget_plan_fact',
    'row_report_codes' => ['budget_plan_fact'],
    'formula' => 'plan-fact-v1',
    'schema' => 'budget_plan_fact_source_v1',
    'parent_report_scope' => 'plan_fact',
    'filters' => [
        ...$snapshotSource['filters'],
        'group_by' => ['month', 'budget_article', 'responsibility_center', 'project', 'currency'],
    ],
    'closure_hash' => str_repeat('5', 64),
];
assert($policy->accepts($planRequest, $planSource));
assert(! $policy->accepts($planRequest, [...$planSource, 'closure_hash' => str_repeat('6', 64)]));

$wipRequest = [
    ...$snapshotRequest,
    'kind' => 'wip_completion_forecast',
    'epm_scope' => 'wip_forecast',
    'currencies' => [],
    'responsibility_center_ids' => [],
    'responsibility_center_uuids' => [],
    'counterparty_ids' => [],
    'formula' => 'project-control-v1',
    'schema' => 'budgeting_epm_data_mart_v1',
];
$wipSource = [
    ...$snapshotSource,
    'report_code' => 'wip_completion_forecast',
    'row_report_codes' => ['wip_completion_forecast'],
    'formula' => 'project-control-v1',
    'schema' => 'budgeting_epm_data_mart_v1',
    'parent_report_scope' => 'wip_forecast',
    'currency' => null,
    'filters' => [],
];
assert($policy->accepts($wipRequest, $wipSource));
assert(! $policy->accepts($wipRequest, [...$wipSource, 'filters' => ['scenario_uuid' => 'foreign']]));

$subsetRequest = [
    ...$snapshotRequest,
    'scope_project_ids' => [10, 20],
    'project_ids' => [10],
];
$subsetSource = [
    ...$snapshotSource,
    'project_id' => null,
    'row_project_ids' => [10],
];
assert($policy->accepts($subsetRequest, $subsetSource));
assert(($policy->canonicalQueryFilters($subsetRequest, $subsetSource)['project_id'] ?? null) === 10);

$unsupportedProjectSubset = [
    ...$snapshotRequest,
    'scope_project_ids' => [10, 20, 30],
    'project_ids' => [10, 20],
];
assert(! $policy->accepts($unsupportedProjectSubset, [
    ...$snapshotSource,
    'project_id' => null,
    'row_project_ids' => [10, 20],
]));

$multiCurrencyRequest = [...$snapshotRequest, 'currencies' => ['RUB', 'USD']];
$multiCurrencySource = [
    ...$snapshotSource,
    'currency' => null,
    'row_currencies' => ['RUB', 'USD'],
];
assert($policy->accepts($multiCurrencyRequest, $multiCurrencySource));
assert(! array_key_exists('currency', $policy->canonicalQueryFilters($multiCurrencyRequest, $multiCurrencySource) ?? []));

$multiDimensionRequest = [
    ...$snapshotRequest,
    'responsibility_center_ids' => [30, 31],
    'responsibility_center_uuids' => [
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
    ],
    'counterparty_ids' => [40, 41],
];
$multiDimensionSource = [
    ...$snapshotSource,
    'filters' => [
        'close_id' => '01K1CLOSE00000000000000000',
        'scenario_uuid' => 'scenario-v1',
        'budget_version_uuid' => 'budget-v1',
        'group_by' => ['project', 'currency'],
    ],
];
assert($policy->accepts($multiDimensionRequest, $multiDimensionSource));
$multiDimensionFilters = $policy->canonicalQueryFilters($multiDimensionRequest, $multiDimensionSource) ?? [];
assert(! array_key_exists('responsibility_center_id', $multiDimensionFilters));
assert(! array_key_exists('counterparty_id', $multiDimensionFilters));
assert(! $policy->accepts($multiDimensionRequest, [
    ...$multiDimensionSource,
    'filters' => [...$multiDimensionSource['filters'], 'responsibility_center_id' => 30],
]));

echo "project_portfolio_health_owner_policy_harness: ok\n";
