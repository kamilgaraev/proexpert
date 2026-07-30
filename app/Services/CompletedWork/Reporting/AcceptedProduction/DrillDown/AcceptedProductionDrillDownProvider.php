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
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLineageFilter;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\ReportSourceObjectAccessAuthorizer;
use Illuminate\Database\Eloquent\Builder;

final readonly class AcceptedProductionDrillDownProvider implements ReportDrillDownProvider
{
    private ImmutableOwnerProjectionReader $reader;

    private ReportSourceObjectAccessAuthorizer $sourceAccess;

    public function __construct(?ReportSourceObjectAccessAuthorizer $sourceAccess = null)
    {
        $this->sourceAccess = $sourceAccess ?? new ReportSourceObjectAccessAuthorizer;
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
            $lineage = CanonicalLineageSummary::fromArray(
                (array) ($row['acceptance_lineage'] ?? []),
            );
            $lineageFilter = AcceptedProductionLineageFilter::fromArray(
                (array) ($row['acceptance_lineage_filter'] ?? []),
            );
            $this->sourceAccess->assertAccessible(
                $context,
                'performance_act',
                (int) $row['performance_act_id'],
                $projectId,
            );
            $this->sourceAccess->assertAccessible(
                $context,
                (string) $row['source_line_type'],
                (int) $row['source_line_id'],
                $projectId,
            );
            if ($row['work_id'] !== null) {
                $this->sourceAccess->assertAccessible(
                    $context,
                    'completed_work',
                    (int) $row['work_id'],
                    $projectId,
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
                'acceptance_lineage' => $lineage->canonicalIdentity(),
                ...array_intersect_key($row, array_flip(['approved_rate_minor', 'accepted_amount_minor'])),
            ];
            $position = LineageCursorPosition::decode($request->cursor);
            $eventQuery = ProductionAcceptanceEvent::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('project_id', $projectId)
                ->where('performance_act_id', (int) $row['performance_act_id'])
                ->where('source_line_type', (string) $row['source_line_type'])
                ->where('source_line_id', (int) $row['source_line_id'])
                ->when(
                    $row['work_id'] === null,
                    static fn (Builder $builder): Builder => $builder->whereNull('work_id'),
                    static fn (Builder $builder): Builder => $builder
                        ->where('work_id', (int) $row['work_id']),
                )
                ->where(function ($builder) use ($lineage): void {
                    $builder
                        ->where('transition_version', '>', $lineage->firstVersion)
                        ->orWhere(function ($lower) use ($lineage): void {
                            $lower
                                ->where('transition_version', $lineage->firstVersion)
                                ->where('id', '>=', $lineage->firstId);
                        });
                })
                ->where(function ($builder) use ($lineage): void {
                    $builder
                        ->where('transition_version', '<', $lineage->lastVersion)
                        ->orWhere(function ($upper) use ($lineage): void {
                            $upper
                                ->where('transition_version', $lineage->lastVersion)
                                ->where('id', '<=', $lineage->lastId);
                        });
                });
            $this->applyLineageFilter($eventQuery, $lineageFilter);
            $eventQuery
                ->when(
                    $position !== null,
                    static function ($builder) use ($position): void {
                        $builder->where(function ($after) use ($position): void {
                            $after
                                ->where('transition_version', '>', $position->version)
                                ->orWhere(function ($tie) use ($position): void {
                                    $tie
                                        ->where('transition_version', $position->version)
                                        ->where('id', '>', $position->id);
                                });
                        });
                    },
                )
                ->orderBy('transition_version')
                ->orderBy('id')
                ->limit($request->limit + 1)
                ->get();
            $hasMore = $eventQuery->count() > $request->limit;
            $events = $eventQuery->take($request->limit);
            foreach ($events as $event) {
                $evidenceRefs = (array) $event->evidence_refs;
                if ($evidenceRefs !== []) {
                    $this->sourceAccess->assertReferencesAccessible(
                        $context,
                        $evidenceRefs,
                        $projectId,
                    );
                }
                $rows[] = [
                    'row_key' => 'acceptance_event:'.$event->id,
                    'accepted_quantity_delta' => (string) $event->accepted_quantity_delta,
                    'event_id' => (int) $event->id,
                    'event_type' => (string) $event->event_type,
                    'project_id' => $projectId,
                    'recognized_at' => $event->recognized_at->format(DATE_ATOM),
                    'source_hash' => (string) $event->source_hash,
                    'transition_version' => (int) $event->transition_version,
                ];
            }
            $lastEvent = $events->last();
            $nextCursor = $hasMore && $lastEvent instanceof ProductionAcceptanceEvent
                ? (new LineageCursorPosition(
                    (int) $lastEvent->transition_version,
                    (int) $lastEvent->id,
                ))->encode()
                : null;
        }

        return new ReportDrillDownResult($rows, $nextCursor ?? null, []);
    }

    private function applyLineageFilter(
        Builder $builder,
        AcceptedProductionLineageFilter $filter,
    ): void {
        $filter->applyTo($builder);
    }
}
