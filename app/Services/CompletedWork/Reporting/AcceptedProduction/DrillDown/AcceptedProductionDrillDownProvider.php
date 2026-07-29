<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionRow;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionSnapshot;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use App\Support\Reporting\ReportSourceObjectAccessAuthorizer;

final readonly class AcceptedProductionDrillDownProvider implements ReportDrillDownProvider
{
    private ImmutableOwnerProjectionReader $reader;

    private ReportSourceObjectAccessAuthorizer $sourceAccess;

    public function __construct(?ReportSourceObjectAccessAuthorizer $sourceAccess = null)
    {
        $this->sourceAccess = $sourceAccess ?? new ReportSourceObjectAccessAuthorizer();
        $this->reader = new ImmutableOwnerProjectionReader(
            AcceptedProductionRow::class,
            AcceptedProductionSnapshot::class,
            ['recognized_on' => 'recognized_on'],
            ['approved_rate_minor', 'accepted_amount_minor'],
        );
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $row = $this->reader->findRow(
            $context,
            $snapshot,
            $this->reader->rowKeyFromToken($request->token),
        );
        $rows = [];
        if ($row !== null) {
            $projectId = (int) $row['project_id'];
            $sourceRefs = array_values(array_filter(
                (array) ($row['source_refs'] ?? []),
                static fn (mixed $sourceRef): bool => is_array($sourceRef)
                    && isset($sourceRef['type'], $sourceRef['id']),
            ));
            if ($sourceRefs === []) {
                $sourceRefs = [
                    ['type' => 'performance_act', 'id' => (int) $row['performance_act_id']],
                    ['type' => (string) $row['source_line_type'], 'id' => (int) $row['source_line_id']],
                    ...($row['work_id'] === null ? [] : [[
                        'type' => 'completed_work',
                        'id' => (int) $row['work_id'],
                    ]]),
                ];
            }
            foreach ($sourceRefs as $sourceRef) {
                $this->sourceAccess->assertAccessible(
                    $context,
                    (string) $sourceRef['type'],
                    (int) $sourceRef['id'],
                    isset($sourceRef['project_id']) ? (int) $sourceRef['project_id'] : $projectId,
                );
            }
            $rows[] = [
                'row_key' => 'accepted_production:'.$row['row_key'],
                'project_id' => $row['project_id'],
                'performance_act_id' => $row['performance_act_id'],
                'source_line_type' => $row['source_line_type'],
                'source_line_id' => $row['source_line_id'],
                'work_id' => $row['work_id'],
                'accepted_quantity' => $row['accepted_quantity'],
                ...array_intersect_key($row, array_flip(['approved_rate_minor', 'accepted_amount_minor'])),
            ];
            foreach ($sourceRefs as $sourceRef) {
                $type = (string) $sourceRef['type'];
                $id = (int) $sourceRef['id'];
                $rows[] = [
                    'row_key' => $type.':'.$id,
                    'project_id' => $row['project_id'],
                    'resource_type' => $type,
                    'resource_id' => $id,
                ];
            }
        }

        return new ReportDrillDownResult($rows, null, []);
    }
}
