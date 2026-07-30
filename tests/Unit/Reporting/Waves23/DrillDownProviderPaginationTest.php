<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown\LookaheadReadinessDrillDownProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown\LookaheadReadinessDrillDownSource;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\AcceptedProductionDrillDownProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\AcceptedProductionDrillDownSource;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLineageFilter;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\LineageEventPage;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DrillDownProviderPaginationTest extends TestCase
{
    #[Test]
    public function lookahead_pages_context_and_events_without_duplicates_or_limit_overrun(): void
    {
        $source = new FakeLookaheadDrillDownSource($this->lookaheadRow(true), [
            $this->constraintEvent(1, 11),
            $this->constraintEvent(2, 12),
            $this->constraintEvent(3, 13),
        ]);
        $provider = new LookaheadReadinessDrillDownProvider(source: $source);
        $cursor = null;
        $keys = [];

        do {
            $result = $provider->drillDown(
                $this->context(),
                $this->snapshot('lookahead_readiness'),
                new ReportDrillDownRequest('token', $cursor, 2),
            );
            self::assertLessThanOrEqual(2, count($result->rows));
            foreach ($result->rows as $row) {
                $keys[] = $row['row_key'];
            }
            $cursor = $result->nextCursor;
        } while ($cursor !== null);

        self::assertSame([
            'lookahead_task:7:11:13:17',
            'work_constraint:17',
            'work_constraint_event:11',
            'work_constraint_event:12',
            'work_constraint_event:13',
        ], $keys);
        self::assertSame(2, $source->eventPageCalls);
    }

    #[Test]
    public function legacy_ready_rows_remain_readable_without_claiming_exact_event_lineage(): void
    {
        $lookaheadSource = new FakeLookaheadDrillDownSource(
            $this->lookaheadRow(false),
            [$this->constraintEvent(1, 11)],
        );
        $lookahead = new LookaheadReadinessDrillDownProvider(source: $lookaheadSource);
        $first = $lookahead->drillDown(
            $this->context(),
            $this->snapshot('lookahead_readiness'),
            new ReportDrillDownRequest('token', null, 1),
        );
        $second = $lookahead->drillDown(
            $this->context(),
            $this->snapshot('lookahead_readiness'),
            new ReportDrillDownRequest('token', $first->nextCursor, 1),
        );

        self::assertSame(['lookahead_task:7:11:13:17'], array_column($first->rows, 'row_key'));
        self::assertSame(['work_constraint:17'], array_column($second->rows, 'row_key'));
        self::assertNull($second->nextCursor);
        self::assertSame(0, $lookaheadSource->eventPageCalls);

        $acceptedSource = new FakeAcceptedProductionDrillDownSource(
            $this->acceptedRow(false),
            [$this->acceptanceEvent(1, 21)],
        );
        $accepted = (new AcceptedProductionDrillDownProvider(source: $acceptedSource))->drillDown(
            $this->context(),
            $this->snapshot('accepted_production_progress'),
            new ReportDrillDownRequest('token', null, 2),
        );

        self::assertSame(
            ['accepted_production:7:2026-07-30:volume:m3:23:performance_act_line:31'],
            array_column($accepted->rows, 'row_key'),
        );
        self::assertNull($accepted->nextCursor);
        self::assertSame(0, $acceptedSource->eventPageCalls);
    }

    #[Test]
    public function accepted_production_pages_exact_events_and_returns_every_authorized_evidence_link(): void
    {
        $source = new FakeAcceptedProductionDrillDownSource($this->acceptedRow(true), [
            $this->acceptanceEvent(1, 21),
            $this->acceptanceEvent(2, 22),
            $this->acceptanceEvent(3, 23),
        ]);
        $provider = new AcceptedProductionDrillDownProvider(source: $source);
        $cursor = null;
        $keys = [];
        $linkedTypes = [];

        do {
            $result = $provider->drillDown(
                $this->context(true),
                $this->snapshot('accepted_production_progress', true),
                new ReportDrillDownRequest('token', $cursor, 2),
            );
            self::assertLessThanOrEqual(2, count($result->rows));
            foreach ($result->rows as $row) {
                $keys[] = $row['row_key'];
            }
            foreach ($result->resourceLinks as $link) {
                $linkedTypes[$link->resourceType] = true;
                self::assertSame('available', $link->availability);
            }
            $cursor = $result->nextCursor;
        } while ($cursor !== null);

        self::assertSame([
            'accepted_production:7:2026-07-30:volume:m3:23:performance_act_line:31',
            'acceptance_event:21',
            'acceptance_event:22',
            'acceptance_event:23',
        ], $keys);
        ksort($linkedTypes);
        self::assertSame([
            'completed_work',
            'construction_journal_entry',
            'performance_act',
            'performance_act_line',
        ], array_keys($linkedTypes));
        self::assertSame(2, $source->eventPageCalls);
    }

    private function context(bool $restricted = false): ReportExecutionContext
    {
        $resources = $restricted ? [
            new ReportScopedResource('act', 23, 7),
            new ReportScopedResource('act_line', 31, 7),
            new ReportScopedResource('work', 37, 7),
            new ReportScopedResource('construction_journal_entry', 43, 7),
        ] : [];
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope(3, [3], [7], $resources, $timezone);

        return new ReportExecutionContext(
            new ReportActor(5, 'active', ['reports.view']),
            $scope,
            new ReportVisibility(true, true, true, true, true, true, true),
            new AuthorizationDecisionContext(
                'http',
                3,
                [3],
                [7],
                $resources,
                $timezone,
                'drill-down-provider-test',
                null,
            ),
        );
    }

    private function snapshot(string $kind, bool $restricted = false): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            $kind,
            'snapshot',
            $this->context($restricted)->scope,
            new Sha256Hash(str_repeat('a', 64)),
            $kind.'.v1',
            new Sha256Hash(str_repeat('b', 64)),
            new DateTimeImmutable('2026-07-30T08:00:00+00:00'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function lookaheadRow(bool $versioned): array
    {
        return [
            'row_key' => '7:11:13:17',
            'project_id' => 7,
            'schedule_id' => 11,
            'task_id' => 13,
            'task_status' => 'planned',
            'task_type' => 'work',
            'task_state_version' => 4,
            'task_state_effective_at' => '2026-07-29T08:00:00+00:00',
            'blocking_constraint_ids' => [17],
            'constraint_id' => 17,
            'constraint_type' => 'permit',
            'constraint_status' => 'open',
            'linked_resource_type' => null,
            'linked_resource_id' => null,
            'transition_lineage' => $versioned
                ? $this->summary(3, 11, 13)
                : [['id' => 11, 'version' => 1]],
            ...($versioned ? [
                'lineage_projection_version' => 2,
                'transition_lineage_as_of' => '2026-07-30T08:00:00.000000+00:00',
            ] : []),
        ];
    }

    private function acceptedRow(bool $versioned): array
    {
        return [
            'row_key' => '7:2026-07-30:volume:m3:23:performance_act_line:31',
            'project_id' => 7,
            'performance_act_id' => 23,
            'source_line_type' => 'performance_act_line',
            'source_line_id' => 31,
            'work_id' => 37,
            'accepted_quantity' => '3.000',
            ...($versioned ? [
                'lineage_projection_version' => 2,
                'acceptance_lineage' => $this->summary(3, 21, 23),
                'acceptance_lineage_filter' => [
                    'as_of' => '2026-07-30T08:00:00.000000+00:00',
                    'contractor_ids' => [],
                    'period_from' => null,
                    'period_to' => null,
                    'statuses' => [],
                    'unit_codes' => [],
                    'zones' => [],
                ],
            ] : []),
        ];
    }

    private function summary(int $count, int $firstId, int $lastId): array
    {
        return [
            'count' => $count,
            'first_id' => $firstId,
            'first_version' => 1,
            'hash' => str_repeat('c', 64),
            'last_id' => $lastId,
            'last_version' => $count,
        ];
    }

    private function constraintEvent(int $version, int $id): WorkConstraintTransitionEvent
    {
        $event = new WorkConstraintTransitionEvent;
        $event->setRawAttributes([
            'id' => $id,
            'constraint_id' => 17,
            'event_version' => $version,
            'from_status' => $version === 1 ? null : 'open',
            'to_status' => 'open',
            'occurred_at' => new CarbonImmutable('2026-07-30T07:00:00+00:00'),
            'source_hash' => hash('sha256', 'constraint-'.$id),
        ]);

        return $event;
    }

    private function acceptanceEvent(int $version, int $id): ProductionAcceptanceEvent
    {
        $event = new ProductionAcceptanceEvent;
        $event->setRawAttributes([
            'id' => $id,
            'performance_act_id' => 23,
            'transition_version' => $version,
            'event_type' => 'accepted',
            'accepted_quantity_delta' => '1.000',
            'recognized_at' => new CarbonImmutable('2026-07-30T07:00:00+00:00'),
            'source_hash' => hash('sha256', 'acceptance-'.$id),
            'evidence_refs' => json_encode([
                ['type' => 'performance_act', 'id' => 23, 'project_id' => 7],
                ['type' => 'performance_act_line', 'id' => 31, 'project_id' => 7],
                ['type' => 'completed_work', 'id' => 37, 'project_id' => 7],
                ['type' => 'construction_journal_entry', 'id' => 43, 'project_id' => 7],
            ], JSON_THROW_ON_ERROR),
        ]);

        return $event;
    }
}

final class FakeLookaheadDrillDownSource implements LookaheadReadinessDrillDownSource
{
    public int $eventPageCalls = 0;

    public function __construct(
        private readonly array $row,
        private readonly array $events,
    ) {}

    public function findRow(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $token,
    ): ?array {
        return $this->row;
    }

    public function eventPage(
        ReportExecutionContext $context,
        array $row,
        CanonicalLineageSummary $lineage,
        string $asOf,
        ?LineageCursorPosition $position,
        int $limit,
    ): LineageEventPage {
        $this->eventPageCalls++;
        $events = array_values(array_filter(
            $this->events,
            static fn (WorkConstraintTransitionEvent $event): bool => $position === null
                || [(int) $event->event_version, (int) $event->id] > [$position->version, $position->id],
        ));

        return new LineageEventPage(
            array_slice($events, 0, $limit),
            count($events) > $limit,
        );
    }
}

final class FakeAcceptedProductionDrillDownSource implements AcceptedProductionDrillDownSource
{
    public int $eventPageCalls = 0;

    public function __construct(
        private readonly array $row,
        private readonly array $events,
    ) {}

    public function findRow(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $token,
    ): ?array {
        return $this->row;
    }

    public function eventPage(
        ReportExecutionContext $context,
        array $row,
        CanonicalLineageSummary $lineage,
        AcceptedProductionLineageFilter $filter,
        ?LineageCursorPosition $position,
        int $limit,
    ): LineageEventPage {
        $this->eventPageCalls++;
        $events = array_values(array_filter(
            $this->events,
            static fn (ProductionAcceptanceEvent $event): bool => $position === null
                || [(int) $event->transition_version, (int) $event->id] > [$position->version, $position->id],
        ));

        return new LineageEventPage(
            array_slice($events, 0, $limit),
            count($events) > $limit,
        );
    }
}
