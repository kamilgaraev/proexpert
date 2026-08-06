<?php

declare(strict_types=1);

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthLiquidityEvidenceFactory;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthReadinessMeasurement;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceComponent;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceGap;

require_once __DIR__.'/../../../app/BusinessModules/Core/Reporting/Support/CanonicalJson.php';
require_once __DIR__.'/../../../app/Enums/CurrencyCode.php';
require_once __DIR__.'/../../../app/BusinessModules/Core/Payments/DTOs/PaymentCalendarItem.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceTupleAssembler.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceComponent.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceGap.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthLiquidityEvidenceFactory.php';
require_once __DIR__.'/../../../app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthReadinessMeasurement.php';

$item = static function (int $sourceId, int $projectId): PaymentCalendarItem {
    $reflection = new ReflectionClass(PaymentCalendarItem::class);
    $item = $reflection->newInstanceWithoutConstructor();
    foreach ([
        'organizationId' => 38,
        'date' => '2026-08-04',
        'originalDate' => null,
        'direction' => 'outflow',
        'bucket' => 'planned',
        'amount' => '100.00',
        'remainingAmount' => '100.00',
        'currency' => 'RUB',
        'probability' => '100.00000000',
        'status' => 'planned',
        'sourceType' => 'payment_document',
        'sourceId' => $sourceId,
        'cashFlowKey' => 'payment_document:'.$sourceId,
        'projectId' => $projectId,
        'counterpartyId' => null,
        'budgetArticleId' => null,
        'responsibilityCenterId' => null,
        'editable' => false,
        'drillDown' => [],
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($item, $value);
    }

    return $item;
};
$version = static fn (int $sourceId, string $hash): array => [
    'source_type' => 'payment_document',
    'source_id' => $sourceId,
    'source_version' => 'v'.$sourceId,
    'source_hash' => str_repeat($hash, 64),
    'history_complete' => true,
];

$measurement = new ProjectPortfolioHealthReadinessMeasurement([
    new ProjectPortfolioHealthSourceGap('source_projection_gap', 'project_margin'),
    new ProjectPortfolioHealthSourceGap('source_integrity_gap', 'project_margin'),
]);
assert(count($measurement->eligible()) === 4);
assert(count($measurement->projected()) === 3);
assert($measurement->gapCount() === 1);

$factory = new ProjectPortfolioHealthLiquidityEvidenceFactory;
$items = [$item(1, 10), $item(2, 20)];
$versions = [$version(1, 'a'), $version(2, 'b')];
$first = $factory->make($items, $versions, [], '2026-08-04T00:00:00+00:00');
$second = $factory->make(array_reverse($items), array_reverse($versions), [], '2026-08-04T00:00:00+00:00');
assert($first instanceof ProjectPortfolioHealthSourceComponent);
assert($second instanceof ProjectPortfolioHealthSourceComponent);
assert($first->sourceHash === $second->sourceHash);
assert($factory->make($items, [$version(1, 'a')], [], '2026-08-04T00:00:00+00:00') instanceof ProjectPortfolioHealthSourceGap);
assert($factory->make($items, [$version(1, 'a'), $version(1, 'b')], [], '2026-08-04T00:00:00+00:00') instanceof ProjectPortfolioHealthSourceGap);
assert($factory->make($items, $versions, [['code' => 'source_projection_gap']], '2026-08-04T00:00:00+00:00') instanceof ProjectPortfolioHealthSourceGap);

echo "project_portfolio_health_readiness_harness: ok\n";
