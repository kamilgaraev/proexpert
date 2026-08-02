<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadConstraintHistoryReducer;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionEventReducer;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLineageFilter;
use App\Support\Reporting\CanonicalLineageAccumulator;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\LineageCursorPosition;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineageReducerBoundednessTest extends TestCase
{
    #[Test]
    public function canonical_lineage_hash_covers_every_ordered_identity_without_retaining_events(): void
    {
        $lineage = new CanonicalLineageAccumulator;
        $lineage->append(1, 11, [
            'id' => 11,
            'source_hash' => str_repeat('a', 64),
            'version' => 1,
        ]);
        $lineage->append(2, 12, [
            'id' => 12,
            'source_hash' => str_repeat('b', 64),
            'version' => 2,
        ]);

        $summary = $lineage->finish();

        self::assertSame(2, $summary->count);
        self::assertSame(1, $summary->firstVersion);
        self::assertSame(11, $summary->firstId);
        self::assertSame(2, $summary->lastVersion);
        self::assertSame(12, $summary->lastId);
        self::assertSame(
            hash(
                'sha256',
                '[{"id":11,"source_hash":"'.str_repeat('a', 64).'","version":1},'
                .'{"id":12,"source_hash":"'.str_repeat('b', 64).'","version":2}]',
            ),
            $summary->hash,
        );
    }

    #[Test]
    public function lookahead_reducer_keeps_large_single_constraint_history_at_fixed_row_size(): void
    {
        $usageBefore = memory_get_usage(true);
        $reducer = new LookaheadConstraintHistoryReducer;
        for ($version = 1; $version <= 20_000; $version++) {
            $event = new WorkConstraintTransitionEvent;
            $event->setRawAttributes([
                'id' => $version,
                'organization_id' => 3,
                'project_id' => 7,
                'schedule_id' => 11,
                'task_id' => 13,
                'constraint_id' => 17,
                'event_version' => $version,
                'from_status' => $version === 1 ? null : 'open',
                'to_status' => 'open',
                'constraint_type' => 'permit',
                'severity' => 'hard',
                'occurred_at' => new CarbonImmutable('2026-07-30T08:00:00+00:00'),
                'source_hash' => hash('sha256', 'constraint-'.$version),
                'evidence_refs' => '[]',
            ]);
            $reducer->append($event);
        }

        $state = $reducer->finish();
        $encoded = json_encode($state->lineage?->canonicalIdentity(), JSON_THROW_ON_ERROR);

        self::assertSame(20_000, $state->lineage?->count);
        self::assertSame(20_000, $state->lineage?->lastVersion);
        self::assertLessThan(512, strlen($encoded));
        self::assertLessThan(8 * 1024 * 1024, memory_get_usage(true) - $usageBefore);
    }

    #[Test]
    public function accepted_production_reducer_keeps_large_single_line_history_at_fixed_row_size(): void
    {
        $usageBefore = memory_get_usage(true);
        $candidate = [
            'event_type' => 'accepted',
            'effective_at' => '2026-07-30T08:00:00+00:00',
            'owner_source_hash' => str_repeat('c', 64),
            'owner_version_id' => 19,
            'performance_act_id' => 23,
            'project_id' => 29,
            'source_line_id' => 31,
            'source_line_type' => 'performance_act_line',
            'work_id' => 37,
        ];
        $reducer = new AcceptedProductionEventReducer($candidate);
        for ($version = 1; $version <= 20_000; $version++) {
            $event = new ProductionAcceptanceEvent;
            $event->setRawAttributes([
                'id' => $version,
                'organization_id' => 3,
                'project_id' => 29,
                'performance_act_id' => 23,
                'source_line_type' => 'performance_act_line',
                'source_line_id' => 31,
                'work_id' => 37,
                'transition_version' => $version,
                'event_type' => 'accepted',
                'accepted_quantity_delta' => '0.001',
                'planned_quantity' => '100.000',
                'reported_quantity' => '80.000',
                'unit_dimension' => 'volume',
                'unit_code' => 'm3',
                'conversion_version' => 'unit_4',
                'approved_rate_minor' => 125_045,
                'currency' => 'RUB',
                'currency_source' => 'performance_act_line.unit_price',
                'recognized_at' => new CarbonImmutable('2026-07-30T08:00:00+00:00'),
                'source_hash' => hash('sha256', 'acceptance-'.$version),
                'evidence_refs' => '[]',
            ]);
            $reducer->append($event);
        }

        $entry = $reducer->finish();
        $encoded = json_encode($entry->lineage->canonicalIdentity(), JSON_THROW_ON_ERROR);

        self::assertSame(20_000, $entry->lineage->count);
        self::assertSame(20_000, $entry->lineage->lastVersion);
        self::assertSame('20.000', $entry->fact->acceptedQuantityDelta);
        self::assertLessThan(512, strlen($encoded));
        self::assertLessThan(8 * 1024 * 1024, memory_get_usage(true) - $usageBefore);
    }

    #[Test]
    public function tuple_cursor_pages_exactly_to_the_pinned_lineage_watermark(): void
    {
        $expected = new CanonicalLineageAccumulator;
        for ($version = 1; $version <= 257; $version++) {
            $expected->append($version, 10_000 + $version, [
                'id' => 10_000 + $version,
                'source_hash' => hash('sha256', 'page-'.$version),
                'version' => $version,
            ]);
        }
        $expectedSummary = $expected->finish();

        $actual = new CanonicalLineageAccumulator;
        $cursor = null;
        do {
            $position = LineageCursorPosition::decode($cursor);
            $page = [];
            for ($version = 1; $version <= 300; $version++) {
                $id = 10_000 + $version;
                if (($position === null || [$version, $id] > [$position->version, $position->id])
                    && [$version, $id] <= [$expectedSummary->lastVersion, $expectedSummary->lastId]
                ) {
                    $page[] = [$version, $id];
                }
                if (count($page) === 37) {
                    break;
                }
            }
            foreach ($page as [$version, $id]) {
                $actual->append($version, $id, [
                    'id' => $id,
                    'source_hash' => hash('sha256', 'page-'.$version),
                    'version' => $version,
                ]);
            }
            $tail = $page[array_key_last($page)] ?? null;
            $cursor = count($page) === 37 && $tail !== null
                ? (new LineageCursorPosition($tail[0], $tail[1]))->encode()
                : null;
        } while ($cursor !== null);

        $actualSummary = $actual->finish();

        self::assertSame($expectedSummary->canonicalIdentity(), $actualSummary->canonicalIdentity());
    }

    #[Test]
    public function accepted_production_reducer_rejects_identity_changes_inside_one_lineage(): void
    {
        $candidate = [
            'performance_act_id' => 23,
            'source_line_id' => 31,
            'source_line_type' => 'performance_act_line',
        ];
        $reducer = new AcceptedProductionEventReducer($candidate);
        $first = $this->acceptanceEvent(1, 37);
        $changed = $this->acceptanceEvent(2, 41);

        $reducer->append($first);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('accepted_production_event_identity_changed');
        $reducer->append($changed);
    }

    #[Test]
    public function persisted_lineage_metadata_is_order_independent_and_strictly_canonical(): void
    {
        $summary = CanonicalLineageSummary::fromArray([
            'last_version' => 7,
            'hash' => str_repeat('a', 64),
            'first_id' => 11,
            'count' => 7,
            'last_id' => 17,
            'first_version' => 1,
        ]);
        $filter = AcceptedProductionLineageFilter::fromArray([
            'zones' => ['north'],
            'unit_codes' => ['m3'],
            'statuses' => ['reversed', 'accepted'],
            'period_to' => '2026-07-30',
            'period_from' => '2026-07-01',
            'contractor_ids' => [41],
            'as_of' => '2026-07-30T08:00:00.000000+00:00',
        ]);

        self::assertSame(7, $summary->count);
        self::assertSame(
            ['accepted', 'reversed'],
            $filter->canonicalIdentity()['statuses'],
        );
        self::assertSame(
            '2026-07-30T08:00:00.000000+00:00',
            $filter->canonicalIdentity()['as_of'],
        );
    }

    private function acceptanceEvent(int $version, int $workId): ProductionAcceptanceEvent
    {
        $event = new ProductionAcceptanceEvent;
        $event->setRawAttributes([
            'id' => $version,
            'organization_id' => 3,
            'project_id' => 29,
            'performance_act_id' => 23,
            'source_line_type' => 'performance_act_line',
            'source_line_id' => 31,
            'work_id' => $workId,
            'transition_version' => $version,
            'event_type' => 'accepted',
            'accepted_quantity_delta' => '0.001',
            'planned_quantity' => '100.000',
            'reported_quantity' => '80.000',
            'unit_dimension' => 'volume',
            'unit_code' => 'm3',
            'conversion_version' => 'unit_4',
            'approved_rate_minor' => 125_045,
            'currency' => 'RUB',
            'currency_source' => 'performance_act_line.unit_price',
            'recognized_at' => new CarbonImmutable('2026-07-30T08:00:00+00:00'),
            'source_hash' => hash('sha256', 'acceptance-'.$version),
            'evidence_refs' => '[]',
        ]);

        return $event;
    }
}
