<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$required = [
    'app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthCandidateContract.php',
    'app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthBuiltinPublishedReport.php',
    'app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthReportBindingFactory.php',
    'app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthPublishedRuntimeBindingRegistrar.php',
];
foreach ($required as $file) {
    assert(is_file($root.'/'.$file), 'missing publication artifact: '.$file);
}

$registry = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Infrastructure/Catalog/BuiltinPublishedReportDefinitionRegistry.php');
$provider = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php');
$budgeting = (string) file_get_contents($root.'/app/BusinessModules/Features/Budgeting/BudgetingServiceProvider.php');
$contract = (string) file_get_contents($root.'/'.$required[0]);
$published = (string) file_get_contents($root.'/'.$required[1]);
$binding = (string) file_get_contents($root.'/'.$required[2]);

foreach ([$registry, $provider, $budgeting] as $source) {
    assert(str_contains($source, 'ProjectPortfolioHealth'), 'R01 publication registration missing');
}
foreach (["'source' => 'ready'", "'formula' => 'ready'", "'delivery' => 'verified'", "'publication' => 'published'", 'budgeting.portfolio_dashboard.view', 'budgeting.portfolio_dashboard.export'] as $needle) {
    assert(str_contains($published, $needle), 'R01 published contract missing: '.$needle);
}
assert(! str_contains($contract, '0.0.0'), 'R01 contract must not use placeholder versions');
assert(str_contains($binding, 'ProjectPortfolioHealthReadinessProbe'), 'R01 binding must fail closed through source readiness');

echo "project portfolio health publication contract: OK\n";
