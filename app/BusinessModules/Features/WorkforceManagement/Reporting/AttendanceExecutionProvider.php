<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotIdentityBuilder;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use InvalidArgumentException;

final readonly class AttendanceExecutionProvider implements ReportDataProvider
{
    public function __construct(
        private WorkforceReportProjectionService $projection,
        private WorkforceReportQueryService $queryService,
        private ReportSnapshotIdentityBuilder $identities,
    ) {
    }

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        if ($query->definition->code !== 'attendance_execution') {
            throw new InvalidArgumentException('attendance_execution_definition_invalid');
        }
        $progress->advance(10);
        $provisional = $this->projection->materializeAttendance($context->scope, $query);
        $canonical = $this->identities->build(
            $query,
            $provisional,
            $this->result($context, $provisional),
        );
        $progress->advance(100);

        return new ReportSnapshotRef(
            kind: $provisional->kind,
            id: $provisional->id,
            scope: $provisional->scope,
            definitionHash: $provisional->definitionHash,
            formulaVersion: $provisional->formulaVersion,
            sourceHash: $canonical,
            generatedAt: $provisional->generatedAt,
            staleAt: $provisional->staleAt,
            watermarks: $provisional->watermarks,
            classification: $provisional->classification,
            seal: $provisional->seal,
            materializedSourceHash: $provisional->materializedSourceHash,
        );
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        return $this->queryService->result($context, $snapshot);
    }
}
