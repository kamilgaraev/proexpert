<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessRow;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\ReportSourceObjectAccessAuthorizer;

final readonly class LookaheadReadinessDrillDownProvider implements ReportDrillDownProvider
{
    private ImmutableOwnerProjectionReader $reader;

    private ReportSourceObjectAccessAuthorizer $sourceAccess;

    public function __construct(?ReportSourceObjectAccessAuthorizer $sourceAccess = null)
    {
        $this->sourceAccess = $sourceAccess ?? new ReportSourceObjectAccessAuthorizer;
        $this->reader = new ImmutableOwnerProjectionReader(
            LookaheadReadinessRow::class,
            LookaheadReadinessSnapshot::class,
            ['planned_start_date' => 'planned_start_date'],
            ['task_state_source_hash'],
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
            $this->sourceAccess->assertAccessible(
                $context,
                'schedule_task',
                (int) $row['task_id'],
                $projectId,
            );
            $rows[] = [
                'row_key' => 'lookahead_task:'.$row['row_key'],
                'project_id' => $row['project_id'],
                'schedule_id' => $row['schedule_id'],
                'task_id' => $row['task_id'],
                'task_status' => $row['task_status'],
                'task_type' => $row['task_type'],
                'task_state_version' => $row['task_state_version'],
                ...(array_key_exists('task_state_source_hash', $row)
                    ? ['task_state_source_hash' => $row['task_state_source_hash']]
                    : []),
                'task_state_effective_at' => $row['task_state_effective_at'],
                'blocking_constraint_ids' => $row['blocking_constraint_ids'],
            ];
            if (($row['constraint_id'] ?? null) !== null) {
                $lineage = CanonicalLineageSummary::fromArray(
                    (array) ($row['transition_lineage'] ?? []),
                );
                $lineageAsOf = $row['transition_lineage_as_of'] ?? null;
                if (! is_string($lineageAsOf)
                    || \DateTimeImmutable::createFromFormat(
                        'Y-m-d\TH:i:s.uP',
                        $lineageAsOf,
                    )?->format('Y-m-d\TH:i:s.uP') !== $lineageAsOf
                ) {
                    throw new \InvalidArgumentException('lookahead_transition_lineage_filter_invalid');
                }
                $this->sourceAccess->assertAccessible(
                    $context,
                    'work_constraint',
                    (int) $row['constraint_id'],
                    $projectId,
                );
                $rows[] = [
                    'row_key' => 'work_constraint:'.$row['constraint_id'],
                    'project_id' => $row['project_id'],
                    'constraint_id' => $row['constraint_id'],
                    'constraint_type' => $row['constraint_type'],
                    'constraint_status' => $row['constraint_status'],
                    'transition_lineage' => $lineage->canonicalIdentity(),
                ];
                $position = LineageCursorPosition::decode($request->cursor);
                $eventQuery = WorkConstraintTransitionEvent::query()
                    ->where('organization_id', $context->scope->organizationId)
                    ->where('project_id', $projectId)
                    ->where('schedule_id', (int) $row['schedule_id'])
                    ->where('task_id', (int) $row['task_id'])
                    ->where('constraint_id', (int) $row['constraint_id'])
                    ->where('occurred_at', '<=', $lineageAsOf)
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
                    ->limit($request->limit + 1)
                    ->get();
                $hasMore = $eventQuery->count() > $request->limit;
                $events = $eventQuery->take($request->limit);
                foreach ($events as $event) {
                    $rows[] = [
                        'row_key' => 'work_constraint_event:'.$event->id,
                        'constraint_id' => (int) $event->constraint_id,
                        'event_id' => (int) $event->id,
                        'event_version' => (int) $event->event_version,
                        'from_status' => $event->from_status,
                        'to_status' => (string) $event->to_status,
                        'occurred_at' => $event->occurred_at->format(DATE_ATOM),
                        'project_id' => $projectId,
                        'source_hash' => (string) $event->source_hash,
                    ];
                }
                $lastEvent = $events->last();
                $nextCursor = $hasMore && $lastEvent instanceof WorkConstraintTransitionEvent
                    ? (new LineageCursorPosition(
                        (int) $lastEvent->event_version,
                        (int) $lastEvent->id,
                    ))->encode()
                    : null;
            }
            if (($row['linked_resource_id'] ?? null) !== null) {
                $this->sourceAccess->assertAccessible(
                    $context,
                    (string) $row['linked_resource_type'],
                    (int) $row['linked_resource_id'],
                    $projectId,
                );
                $rows[] = [
                    'row_key' => $row['linked_resource_type'].':'.$row['linked_resource_id'],
                    'project_id' => $row['project_id'],
                    'resource_type' => $row['linked_resource_type'],
                    'resource_id' => $row['linked_resource_id'],
                ];
            }
        }

        return new ReportDrillDownResult($rows, $nextCursor ?? null, []);
    }
}
