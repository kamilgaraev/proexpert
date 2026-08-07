<?php

declare(strict_types=1);

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthOwnerCandidateSelector;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceComponent;

require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceTupleAssembler.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceComponent.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthOwnerCandidateSelector.php';

$exact = new ProjectPortfolioHealthSourceComponent(
    'project_margin',
    '01K1EXACT00000000000000000',
    str_repeat('1', 64),
    'project_margin_core.v1|project_margin_source_v1',
    '2026-08-04T00:00:00+00:00',
);
$foreign = new ProjectPortfolioHealthSourceComponent(
    'project_margin',
    '01K1FOREIGN000000000000000',
    str_repeat('2', 64),
    'project_margin_core.v1|project_margin_source_v1',
    '2026-08-04T00:00:00+00:00',
);
$selector = new ProjectPortfolioHealthOwnerCandidateSelector;
$selection = $selector->select([
    [
        'classification' => 'exact',
        'identity_key' => 'definition-a:query-a',
        'component' => $exact,
        'cohort' => str_repeat('3', 64),
    ],
    ['classification' => 'unresolved', 'identity_key' => 'definition-b:query-b'],
]);

assert($selection['component'] === $exact);
assert($selection['cohort'] === str_repeat('3', 64));
assert($selection['gap_code'] === null);

$targetInvalid = $selector->select([
    [
        'classification' => 'exact',
        'identity_key' => 'definition-a:query-a',
        'component' => $exact,
    ],
    ['classification' => 'unresolved', 'identity_key' => 'definition-a:query-a'],
]);
assert($targetInvalid['component'] === null);
assert($targetInvalid['gap_code'] === 'owner_source_integrity_invalid');

$ambiguous = $selector->select([
    ['classification' => 'exact', 'identity_key' => 'definition-a:query-a', 'component' => $exact],
    ['classification' => 'exact', 'identity_key' => 'definition-b:query-b', 'component' => $foreign],
]);
assert($ambiguous['component'] === null);
assert($ambiguous['gap_code'] === 'owner_source_integrity_ambiguous');

assert($selector->identitySetIsComplete(['valid'], ['valid']));
assert(! $selector->identitySetIsComplete(['valid'], ['corrupt', 'valid']));

echo "project_portfolio_health_owner_candidate_selector_harness: ok\n";
