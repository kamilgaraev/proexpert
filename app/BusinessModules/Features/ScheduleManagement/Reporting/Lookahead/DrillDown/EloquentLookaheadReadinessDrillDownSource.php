<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessRow;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\LineageEventPage;

final readonly class EloquentLookaheadReadinessDrillDownSource implements LookaheadReadinessDrillDownSource
{
    private ImmutableOwnerProjectionReader $reader;

    public function __construct()
    {
        $this->reader = new ImmutableOwnerProjectionReader(
            LookaheadReadinessRow::class,
            LookaheadReadinessSnapshot::class,
            ['planned_start_date' => 'planned_start_date'],
            ['task_state_source_hash'],
        );
    }

    public function findRow(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $rowKey,
    ): ?array {
        return $this->reader->findRow(
            $context,
            $snapshot,
            $rowKey,
        );
    }

    public function eventPage(
        ReportExecutionContext $context,
        array $row,
        CanonicalLineageSummary $lineage,
        string $asOf,
        ?LineageCursorPosition $position,
        int $limit,
    ): LineageEventPage {
        $records = WorkConstraintTransitionEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('project_id', (int) $row['project_id'])
            ->where('schedule_id', (int) $row['schedule_id'])
            ->where('task_id', (int) $row['task_id'])
            ->where('constraint_id', (int) $row['constraint_id'])
            ->where('occurred_at', '<=', $asOf)
            ->where(function ($builder) use ($lineage): void {
                $builder
                    ->where('event_version', '>', $lineage->firstVersion)
                    ->orWhere(function ($lower) use ($lineage): void {
                        $lower
                            ->where('event_version', $lineage->firstVersion)
                            ->where('id', '>=', $lineage->firstId);
                    });
            })
            ->where(function ($builder) use ($lineage): void {
                $builder
                    ->where('event_version', '<', $lineage->lastVersion)
                    ->orWhere(function ($upper) use ($lineage): void {
                        $upper
                            ->where('event_version', $lineage->lastVersion)
                            ->where('id', '<=', $lineage->lastId);
                    });
            })
            ->when(
                $position !== null,
                static function ($builder) use ($position): void {
                    $builder->where(function ($after) use ($position): void {
                        $after
                            ->where('event_version', '>', $position->version)
                            ->orWhere(function ($tie) use ($position): void {
                                $tie
                                    ->where('event_version', $position->version)
                                    ->where('id', '>', $position->id);
                            });
                    });
                },
            )
            ->orderBy('event_version')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;

        return new LineageEventPage(
            $records->take($limit)->values()->all(),
            $hasMore,
        );
    }
}
