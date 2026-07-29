<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\PayrollCalculationVersion;

interface PayrollReadinessDatabasePort
{
    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef;

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult;

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage;

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable;

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult;

    public function buildVersion(int $organizationId, int $periodId, int $actorId): PayrollCalculationVersion;

    public function validateVersion(int $organizationId, int $calculationVersionId, int $actorId): PayrollCalculationVersion;

    public function lockVersion(int $organizationId, int $calculationVersionId, int $actorId): PayrollCalculationVersion;

    public function currentVersion(int $organizationId, int $periodId): ?PayrollCalculationVersion;
}
