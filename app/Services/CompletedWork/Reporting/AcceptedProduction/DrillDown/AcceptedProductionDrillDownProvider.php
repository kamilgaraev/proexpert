<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLineageFilter;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\DrillDownPageCursor;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\ReportSourceObjectAccessAuthorizer;
use InvalidArgumentException;

final readonly class AcceptedProductionDrillDownProvider implements ReportDrillDownProvider
{
    private const LINEAGE_PROJECTION_VERSION = 2;

    private ReportSourceObjectAccessAuthorizer $sourceAccess;

    private AcceptedProductionDrillDownSource $source;

    public function __construct(
        ?ReportSourceObjectAccessAuthorizer $sourceAccess = null,
        ?AcceptedProductionDrillDownSource $source = null,
    ) {
        $this->sourceAccess = $sourceAccess ?? new ReportSourceObjectAccessAuthorizer;
        $this->source = $source ?? new EloquentAcceptedProductionDrillDownSource;
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $row = $this->source->findRow($context, $snapshot, $request->token);
        if ($row === null) {
            return new ReportDrillDownResult([], null, []);
        }

        $projectId = (int) $row['project_id'];
        $contextRows = $this->contextRows($context, $row, $projectId);
        $projectionVersion = $this->projectionVersion($row);
        $cursor = DrillDownPageCursor::decode($request->cursor);
        [$rows, $remaining, $nextContextCursor] = $this->contextPage(
            $contextRows,
            $cursor,
            $request->limit,
        );
        if ($nextContextCursor !== null) {
            return new ReportDrillDownResult($rows, $nextContextCursor, []);
        }
        if ($projectionVersion === 1) {
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
            (array) ($row['acceptance_lineage'] ?? []),
        );
        $lineageFilter = AcceptedProductionLineageFilter::fromArray(
            (array) ($row['acceptance_lineage_filter'] ?? []),
        );
        $eventPage = $this->source->eventPage(
            $context,
            $row,
            $lineage,
            $lineageFilter,
            $cursor?->eventPosition,
            $remaining,
        );
        $links = [];
        foreach ($eventPage->events as $event) {
            if (! $event instanceof ProductionAcceptanceEvent) {
                throw new InvalidArgumentException('accepted_production_lineage_event_invalid');
            }
            $evidenceRefs = (array) $event->evidence_refs;
            $this->sourceAccess->assertReferencesAccessible(
                $context,
                $evidenceRefs,
                $projectId,
            );
            foreach ($this->evidenceLinks($evidenceRefs, $event, $projectId) as $link) {
                $links[$link->resourceType.':'.$link->resourceId] = $link;
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
        $lastEvent = $eventPage->events[array_key_last($eventPage->events)] ?? null;
        $nextCursor = $eventPage->hasMore && $lastEvent instanceof ProductionAcceptanceEvent
            ? DrillDownPageCursor::events(new LineageCursorPosition(
                (int) $lastEvent->transition_version,
                (int) $lastEvent->id,
            ))->encode()
            : null;

        return new ReportDrillDownResult($rows, $nextCursor, array_values($links));
    }

    private function contextRows(
        ReportExecutionContext $context,
        array $row,
        int $projectId,
    ): array {
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
        $contextRow = [
            'row_key' => 'accepted_production:'.$row['row_key'],
            'project_id' => $row['project_id'],
            'performance_act_id' => $row['performance_act_id'],
            'source_line_type' => $row['source_line_type'],
            'source_line_id' => $row['source_line_id'],
            'work_id' => $row['work_id'],
            'accepted_quantity' => $row['accepted_quantity'],
            ...array_intersect_key($row, array_flip(['approved_rate_minor', 'accepted_amount_minor'])),
        ];
        if (array_key_exists('acceptance_lineage', $row)) {
            $contextRow['acceptance_lineage'] = $row['acceptance_lineage'];
        }

        return [$contextRow];
    }

    private function projectionVersion(array $row): int
    {
        $version = $row['lineage_projection_version'] ?? 1;
        if (! is_int($version) || ! in_array($version, [1, self::LINEAGE_PROJECTION_VERSION], true)) {
            throw new InvalidArgumentException('accepted_production_lineage_projection_version_invalid');
        }

        return $version;
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

    private function evidenceLinks(
        array $references,
        ProductionAcceptanceEvent $event,
        int $projectId,
    ): array {
        $links = [];
        foreach ($references as $reference) {
            if (! is_array($reference)
                || ! is_string($reference['type'] ?? null)
                || ! is_numeric($reference['id'] ?? null)
            ) {
                throw new InvalidArgumentException('accepted_production_evidence_reference_invalid');
            }
            $type = $reference['type'];
            $id = (int) $reference['id'];
            [$routeName, $params] = match ($type) {
                'performance_act' => [
                    'admin.performance_acts.show',
                    ['performance_act' => $id],
                ],
                'performance_act_line' => [
                    'admin.performance_acts.show',
                    ['performance_act' => (int) $event->performance_act_id],
                ],
                'completed_work' => [
                    'admin.completed_works.show',
                    ['project_id' => $projectId, 'completed_work' => $id],
                ],
                'construction_journal_entry' => [
                    'admin.journal_entries.show',
                    ['entry' => $id],
                ],
                default => throw new InvalidArgumentException(
                    'accepted_production_evidence_link_unsupported',
                ),
            };
            $links[] = new ReportResourceLink(
                resourceType: $type,
                resourceId: $type.'_'.$id,
                routeName: $routeName,
                params: $params,
                availability: 'available',
            );
        }

        return $links;
    }
}
