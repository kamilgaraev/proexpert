<?php

declare(strict_types=1);

require_once __DIR__.'/brick_math_harness_stub.php';
require_once __DIR__.'/../../../app/BusinessModules/Core/Reporting/Application/Errors/ReportErrorCode.php';
require_once __DIR__.'/../../../app/BusinessModules/Core/Reporting/Application/Errors/ReportContractException.php';
require_once __DIR__.'/../../../app/BusinessModules/Core/Reporting/Support/CanonicalJson.php';
require_once __DIR__.'/../../../app/BusinessModules/Core/Reporting/Domain/ValueObjects/Sha256Hash.php';
require_once __DIR__.'/../../../app/BusinessModules/Core/Reporting/Domain/DTO/ReportSourceRef.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/Support/PortfolioDecimal.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/DTO/ProjectPortfolioHealthRow.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/DTO/ProjectPortfolioProjectionResult.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceGap.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceComponent.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceTuple.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceTupleAssembler.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthImmutableSourceSelection.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthRuntimeFilter.php';

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioHealthRow;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioProjectionResult;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthImmutableSourceSelection;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthRuntimeFilter;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceComponent;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceTuple;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceTupleAssembler;

$mode = $argv[1] ?? 'all';

$tuple = static function (string $seed = 'source'): ProjectPortfolioHealthSourceTuple {
    $components = [];
    foreach (ProjectPortfolioHealthSourceTupleAssembler::REQUIRED_KINDS as $index => $kind) {
        $components[] = new ProjectPortfolioHealthSourceComponent(
            $kind,
            'snapshot-'.($index + 1),
            hash('sha256', $seed.'-'.($index + 1)),
            '1.0.0|1.0.0',
            '2026-08-07T00:00:00+00:00',
        );
    }

    return (new ProjectPortfolioHealthSourceTupleAssembler)->assemble($components);
};

$ownerPayloads = static fn (): array => [
    'project_margin' => ['rows' => [[
        'project' => ['id' => 7, 'name' => 'Проект 7'],
        'currency' => 'RUB',
        'actual' => ['revenue' => '100.00', 'cost' => '80.00', 'gross_margin' => '20.00'],
        'forecast' => ['revenue' => '100.00', 'cost' => '80.00', 'gross_margin' => '20.00'],
        'source_refs' => [],
    ]]],
    'wip_completion_forecast' => ['rows' => [[
        'project' => ['id' => 7, 'name' => 'Проект 7'],
        'currency' => 'RUB',
        'metrics' => ['wip' => '0.00', 'wip_total' => '0.00', 'ftc' => '0.00', 'eac' => '0.00', 'ctc' => '0.00'],
        'source_refs' => [],
    ]]],
    'budget_plan_fact' => ['rows' => [[
        'project' => ['id' => 7, 'name' => 'Проект 7'],
        'currency' => 'RUB',
        'variance_amount' => '0.00',
        'risk_level' => 'low',
        'source_refs' => [],
    ]]],
];

$selection = static function (
    array $projectReferences,
    ?ProjectPortfolioHealthSourceTuple $sourceTuple = null,
) use ($tuple, $ownerPayloads): ProjectPortfolioHealthImmutableSourceSelection {
    $parameters = (new ReflectionClass(ProjectPortfolioHealthImmutableSourceSelection::class))
        ->getConstructor()?->getParameters() ?? [];
    $names = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters);
    assert(in_array('projectReferences', $names, true), 'current project dimensions must be explicit immutable input');

    return new ProjectPortfolioHealthImmutableSourceSelection(
        $sourceTuple ?? $tuple(),
        $ownerPayloads(),
        [],
        ['project_margin' => 1, 'wip_completion_forecast' => 1, 'budget_plan_fact' => 1],
        $projectReferences,
    );
};

$runtime = static function (): ProjectPortfolioHealthRuntimeFilter {
    assert(class_exists(ProjectPortfolioHealthRuntimeFilter::class), 'runtime filter implementation is missing');

    return new ProjectPortfolioHealthRuntimeFilter;
};

$row = static fn (int $projectId, string $riskLevel, int $riskRank, string $revenue): ProjectPortfolioHealthRow => new ProjectPortfolioHealthRow(
    projectId: $projectId,
    projectName: 'Проект '.$projectId,
    currency: 'RUB',
    revenue: $revenue,
    cost: '10.00',
    wip: '0.00',
    ftc: '0.00',
    eac: '0.00',
    ctc: '0.00',
    riskLevel: $riskLevel,
    riskRank: $riskRank,
    asOf: '2026-08-07',
    sourceRefs: [['type' => 'project', 'id' => $projectId]],
);

$assertFilterError = static function (callable $callback): void {
    try {
        $callback();
        assert(false, 'invalid or out-of-scope filter value must fail closed');
    } catch (ReportContractException $exception) {
        assert($exception->errorCode === ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
    }
};

if ($mode === 'identity' || $mode === 'all') {
    $base = $selection([['id' => 7, 'status' => 'active', 'manager_ids' => [31, 33]]]);
    $sameNormalized = $selection([['id' => 7, 'status' => 'active', 'manager_ids' => [33, 31, 31]]]);
    $otherStatus = $selection([['id' => 7, 'status' => 'paused', 'manager_ids' => [31, 33]]]);
    $otherManager = $selection([['id' => 7, 'status' => 'active', 'manager_ids' => [31, 34]]]);
    $otherTuple = $selection(
        [['id' => 7, 'status' => 'active', 'manager_ids' => [31, 33]]],
        $tuple('other-source'),
    );

    assert($base->sourceHash()->value === $sameNormalized->sourceHash()->value);
    assert($base->sourceHash()->value !== $otherStatus->sourceHash()->value);
    assert($base->sourceHash()->value !== $otherManager->sourceHash()->value);
    assert($base->sourceHash()->value !== $otherTuple->sourceHash()->value);
    assert($base->watermarks()['project_dimensions'] === $sameNormalized->watermarks()['project_dimensions']);
    assert($base->watermarks()['project_dimensions'] !== $otherStatus->watermarks()['project_dimensions']);
    assert(count($base->sourceRefs()) === 5);
    $componentIdentity = static fn (array $refs): array => array_map(
        static fn (object $ref): array => [$ref->source, $ref->snapshotId, $ref->hash->value],
        array_slice($refs, 0, -1),
    );
    assert($componentIdentity($base->sourceRefs()) === $componentIdentity($otherStatus->sourceRefs()));
    assert($base->sourceRefs()[4]->source === 'project_dimensions');
    assert($base->sourceRefs()[4]->hash->value !== $otherStatus->sourceRefs()[4]->hash->value);
    assert($base->sourceRefs()[4]->watermark !== $otherManager->sourceRefs()[4]->watermark);
    assert($base->projects()[7]['status'] === 'active');
    assert($base->projects()[7]['manager_ids'] === [31, 33]);
}

if ($mode === 'manager_status' || $mode === 'all') {
    $projects = [
        7 => ['id' => 7, 'name' => 'Проект 7', 'status' => 'active', 'manager_ids' => [31, 33]],
        8 => ['id' => 8, 'name' => 'Проект 8', 'status' => 'paused', 'manager_ids' => [32]],
        9 => ['id' => 9, 'name' => 'Проект 9', 'status' => 'active', 'manager_ids' => []],
    ];
    $filtered = $runtime()->projects($projects, [31, 32], ['active']);

    assert(array_keys($filtered) === [7]);
}

if ($mode === 'empty_intersection' || $mode === 'all') {
    $projects = [
        7 => ['id' => 7, 'name' => 'Проект 7', 'status' => 'active', 'manager_ids' => [31]],
        8 => ['id' => 8, 'name' => 'Проект 8', 'status' => 'paused', 'manager_ids' => [32]],
    ];
    $filtered = $runtime()->projects($projects, [31], ['paused']);
    assert($filtered === []);

    $projectionService = file_get_contents(
        __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthImmutableProjectionService.php',
    );
    assert(is_string($projectionService));
    assert(! str_contains($projectionService, 'if ($projection->rows === [])'));
}

if ($mode === 'risk' || $mode === 'all') {
    $filter = $runtime();
    assert(method_exists($filter, 'riskRowIndexes'), 'final risk row filter is missing');
    $projection = ProjectPortfolioProjectionResult::fromRows([
        $row(7, 'low', 1, '20.00'),
        $row(8, 'high', 3, '30.00'),
        $row(9, 'critical', 4, '40.00'),
    ], '2026-08-07T00:00:00+00:00', 50);
    $indexes = $filter->riskRowIndexes(array_map(
        static fn (ProjectPortfolioHealthRow $item): string => $item->riskLevel,
        $projection->rows,
    ), ['high', 'critical']);
    $filteredProjection = ProjectPortfolioProjectionResult::fromRows(array_map(
        static fn (int $index): ProjectPortfolioHealthRow => $projection->rows[$index],
        $indexes,
    ), '2026-08-07T00:00:00+00:00', 50);

    assert($indexes === [1, 2]);
    assert(array_map(
        static fn (ProjectPortfolioHealthRow $item): string => $item->riskLevel,
        $filteredProjection->rows,
    ) === ['high', 'critical']);
    assert($filteredProjection->totalsByCurrency['RUB']['revenue'] === '70.00');
    assert($filteredProjection->totalsByCurrency['RUB']['cost'] === '20.00');
    assert($filteredProjection->totalsByCurrency['RUB']['margin'] === '50.00');
}

if ($mode === 'invalid' || $mode === 'all') {
    $projects = [
        7 => ['id' => 7, 'name' => 'Проект 7', 'status' => 'active', 'manager_ids' => [31]],
    ];

    $assertFilterError(static fn () => $runtime()->projects($projects, [999], []));
    $assertFilterError(static fn () => $runtime()->projects($projects, [], ['paused']));
    $assertFilterError(static fn () => $runtime()->projects($projects, ['invalid'], []));
    $assertFilterError(static fn () => $runtime()->riskRowIndexes(['low', 'high'], ['medium']));
    $assertFilterError(static fn () => $runtime()->riskRowIndexes(['low', 'high'], ['unknown']));
}

if ($mode === 'wiring' || $mode === 'all') {
    $projectionService = file_get_contents(
        __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthImmutableProjectionService.php',
    );
    $sourceReader = file_get_contents(
        __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/EloquentProjectPortfolioHealthSourceReader.php',
    );
    $immutableSource = file_get_contents(
        __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthImmutableSource.php',
    );
    assert(is_string($projectionService));
    assert(is_string($sourceReader));
    assert(is_string($immutableSource));

    $projectsPosition = strpos($projectionService, '$this->runtimeFilters->projects(');
    $riskPosition = strpos($projectionService, '$this->runtimeFilters->riskRowIndexes(');
    $persistPosition = strpos($projectionService, '$this->snapshots->persistHealth(');
    assert(is_int($projectsPosition));
    assert(is_int($riskPosition));
    assert(is_int($persistPosition));
    assert($projectsPosition < $riskPosition && $riskPosition < $persistPosition);
    assert(str_contains($projectionService, 'ProjectPortfolioProjectionResult::fromRows(array_map('));
    assert(! str_contains($projectionService, 'if ($projection->rows === [])'));

    assert(str_contains($sourceReader, '->accessibleByOrganization($organizationId)'));
    assert(str_contains($sourceReader, "->whereIn('id', \$projectIds)"));
    assert(str_contains($sourceReader, "->wherePivot('role', 'project_manager')"));
    assert(str_contains($sourceReader, "->wherePivot('is_active', true)"));
    assert(str_contains($sourceReader, "'projects' => \$projectReferences"));
    assert(str_contains($sourceReader, "array_column(\$references, 'id') === \$projectIds"));
    assert(str_contains($immutableSource, "! is_array(\$read['projects'] ?? null)"));
    assert(str_contains($immutableSource, "\$read['projects'],"));
}

echo "project_portfolio_health_runtime_filter_harness: {$mode}: ok\n";
