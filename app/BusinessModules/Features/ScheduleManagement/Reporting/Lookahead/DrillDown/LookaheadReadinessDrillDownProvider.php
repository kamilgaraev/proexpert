<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\DrillDownPageCursor;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\ReportSourceObjectAccessAuthorizer;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadReadinessDrillDownProvider implements ReportDrillDownProvider
{
    private const LINEAGE_PROJECTION_VERSION = 2;

    private ReportSourceObjectAccessAuthorizer $sourceAccess;

    private LookaheadReadinessDrillDownSource $source;

    public function __construct(
        ?ReportSourceObjectAccessAuthorizer $sourceAccess = null,
        ?LookaheadReadinessDrillDownSource $source = null,
    ) {
        $this->sourceAccess = $sourceAccess ?? new ReportSourceObjectAccessAuthorizer;
        $this->source = $source ?? new EloquentLookaheadReadinessDrillDownSource;
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $row = $this->source->findRow($context, $snapshot, $input->cell->rowKey);
        if ($row === null) {
            return new ReportDrillDownResult([], null, []);
        }

        $projectId = (int) $row['project_id'];
        $contextRows = $this->contextRows($context, $row, $projectId);
        $projectionVersion = $this->projectionVersion($row);
        $cursor = DrillDownPageCursor::decode($input->cursor);
        [$rows, $remaining, $nextContextCursor] = $this->contextPage(
            $contextRows,
            $cursor,
            $input->limit,
        );
        if ($nextContextCursor !== null) {
            return new ReportDrillDownResult($rows, $nextContextCursor, []);
        }
        if ($projectionVersion === 1 || ($row['constraint_id'] ?? null) === null) {
            return new ReportDrillDownResult($rows, null, []);
        }
        if ($remaining === 0) {
            return new ReportDrillDownResult(
                $rows,
                DrillDownPageCursor::context(count($contextRows))->encode(),
                [],
            );
        }

        $lineage = CanonicalLineageSummary::fromArray(
            (array) ($row['transition_lineage'] ?? []),
        );
        $lineageAsOf = $this->lineageAsOf($row);
        $eventPage = $this->source->eventPage(
            $context,
            $row,
            $lineage,
            $lineageAsOf,
            $cursor?->eventPosition,
            $remaining,
        );
        foreach ($eventPage->events as $event) {
            if (! $event instanceof WorkConstraintTransitionEvent) {
                throw new InvalidArgumentException('lookahead_transition_lineage_event_invalid');
            }
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
        $lastEvent = $eventPage->events[array_key_last($eventPage->events)] ?? null;
        $nextCursor = $eventPage->hasMore && $lastEvent instanceof WorkConstraintTransitionEvent
            ? DrillDownPageCursor::events(new LineageCursorPosition(
                (int) $lastEvent->event_version,
                (int) $lastEvent->id,
            ))->encode()
            : null;

        return new ReportDrillDownResult($rows, $nextCursor, []);
    }

    private function contextRows(
        ReportExecutionContext $context,
        array $row,
        int $projectId,
    ): array {
        $this->sourceAccess->assertAccessible(
            $context,
            'schedule_task',
            (int) $row['task_id'],
            $projectId,
        );
        $rows = [[
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
        ]];
        if (($row['constraint_id'] ?? null) !== null) {
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
                'transition_lineage' => $row['transition_lineage'] ?? [],
            ];
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

        return $rows;
    }

    private function projectionVersion(array $row): int
    {
        $version = $row['lineage_projection_version'] ?? 1;
        if (! is_int($version) || ! in_array($version, [1, self::LINEAGE_PROJECTION_VERSION], true)) {
            throw new InvalidArgumentException('lookahead_lineage_projection_version_invalid');
        }

        return $version;
    }

    private function lineageAsOf(array $row): string
    {
        $value = $row['transition_lineage_as_of'] ?? null;
        if (! is_string($value)
            || DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i:s.uP',
                $value,
            )?->format('Y-m-d\TH:i:s.uP') !== $value
        ) {
            throw new InvalidArgumentException('lookahead_transition_lineage_filter_invalid');
        }

        return $value;
    }

    private function contextPage(
        array $contextRows,
        ?DrillDownPageCursor $cursor,
        int $limit,
    ): array {
        if ($cursor?->eventPosition !== null) {
            return [[], $limit, null];
        }
        $offset = $cursor?->contextOffset ?? 0;
        if ($offset > count($contextRows)) {
            throw new InvalidArgumentException('drill_down_page_cursor_invalid');
        }
        $rows = array_slice($contextRows, $offset, $limit);
        $nextOffset = $offset + count($rows);
        $nextCursor = $nextOffset < count($contextRows)
            ? DrillDownPageCursor::context($nextOffset)->encode()
            : null;

        return [$rows, $limit - count($rows), $nextCursor];
    }
}
