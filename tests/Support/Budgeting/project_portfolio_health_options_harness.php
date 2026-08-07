<?php

declare(strict_types=1);

assert_options_file('app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ProjectPortfolioHealthReportOptionsController.php');
assert_options_file('app/BusinessModules/Core/Reporting/Http/Admin/Requests/ProjectPortfolioHealthReportOptionsRequest.php');
assert_options_file('app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthOptionsService.php');

$root = dirname(__DIR__, 3);
$routes = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/routes.php');
$service = (string) file_get_contents($root.'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthOptionsService.php');
$request = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Http/Admin/Requests/ProjectPortfolioHealthReportOptionsRequest.php');

foreach ([
    "Route::get('/project-portfolio-health/options'",
    "->defaults('reportCode', 'project_portfolio_health')",
    "'report.organization-scope', \$resourceAccess",
] as $needle) {
    assert(str_contains($routes, $needle), 'route contract missing: '.$needle);
}

foreach ([
    'StableReportingSourceView',
    'Project::accessibleByOrganization($scope->organizationId)',
    '->whereIn(\'id\', $scope->projectIds)',
    "->where('project_user.role', 'project_manager')",
    "->where('project_user.is_active', true)",
    'CurrencyCode::options()',
    "'period'", "'projects'", "'managers'", "'project_statuses'", "'responsibility_centers'", "'counterparties'", "'currencies'", "'risk_levels'",
    'REPORT_SOURCE_UNAVAILABLE',
] as $needle) {
    assert(str_contains($service, $needle), 'options contract missing: '.$needle);
}

foreach (["\$this->forbiddenClientFieldsRules()", "'project_id' => ['prohibited']", "'scope' => ['prohibited']"] as $needle) {
    assert(str_contains($request, $needle), 'server-owned request contract missing: '.$needle);
}

assert(! str_contains($routes, "Route::post('/project-portfolio-health/runs'"), 'options release must not publish R01 run route');
assert(! str_contains($routes, 'BuiltinPublishedReportDefinitionRegistry'), 'options release must not mutate registry');

echo "project portfolio health options contract: OK\n";

function assert_options_file(string $relative): void
{
    assert(is_file(dirname(__DIR__, 3).'/'.$relative), 'missing required file: '.$relative);
}
