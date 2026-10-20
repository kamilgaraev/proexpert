<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotPlanProfile;
use PHPUnit\Framework\TestCase;

final class AssistantStatusSnapshotPlanProfileTest extends TestCase
{
    public function test_profile_contains_only_closed_numeric_fields_and_caps_slow_events_per_capture(): void
    {
        $profile = new AssistantStatusSnapshotPlanProfile;
        $plan = [['Planning Time' => 12.345, 'Plan' => [
            'Node Type' => 'SECRET_NODE', 'Relation Name' => 'SECRET_RELATION',
            'Plan Rows' => 9, 'Total Cost' => 18.321,
            'Filter' => 'SECRET_VALUE', 'Output' => ['SECRET_SQL'],
            'Plans' => [['Node Type' => 'SECRET_SUBNODE', 'Subplan Name' => 'SECRET_SUBPLAN']],
        ]]];
        self::assertNull($profile->sample($plan, 499.99, 1, 500, 100, 2, 1));
        self::assertNull($profile->sample($plan, NAN, 1, 500, 100, 2, 1));
        self::assertNull($profile->sample($plan, INF, 1, 500, 100, 2, 1));
        $summary = $profile->sample($plan, 500, 1.123, 500, 100, 2, 3);
        self::assertNotNull($summary);
        self::assertSame([
            'select_elapsed_ms', 'decode_elapsed_ms', 'planning_ms', 'plan_bytes', 'query_bytes', 'bindings_count',
            'plan_ordinal', 'root_plan_rows', 'root_total_cost', 'plan_nodes', 'relation_nodes', 'subplan_nodes',
            'max_array_depth', 'entries_visited', 'truncated',
        ], array_keys($summary));
        self::assertSame(12.35, $summary['planning_ms']);
        self::assertSame(9.0, $summary['root_plan_rows']);
        self::assertSame(18.32, $summary['root_total_cost']);
        self::assertSame(1.12, $summary['decode_elapsed_ms']);
        self::assertSame(2, $summary['plan_nodes']);
        self::assertSame(1, $summary['relation_nodes']);
        self::assertSame(1, $summary['subplan_nodes']);
        self::assertSame(3, $summary['plan_ordinal']);
        self::assertFalse($summary['truncated']);
        foreach ($summary as $value) {
            self::assertTrue(is_int($value) || is_float($value) || is_bool($value) || $value === null);
        }
        self::assertStringNotContainsString('SECRET', json_encode($summary, JSON_THROW_ON_ERROR));
        for ($event = 2; $event <= 8; $event++) {
            self::assertNotNull($profile->sample($plan, 500, 1, 500, 100, 2, $event));
        }
        self::assertNull($profile->sample($plan, 500, 1, 500, 100, 2, 9));
        self::assertNotNull((new AssistantStatusSnapshotPlanProfile)->sample($plan, 500, 1, 500, 100, 2, 1));
    }

    public function test_invalid_numeric_metadata_is_not_cast_or_logged(): void
    {
        foreach ([NAN, INF, -1, 'SECRET', '123', 1.0e16, [], false] as $value) {
            $summary = (new AssistantStatusSnapshotPlanProfile)->sample(
                [['Planning Time' => $value, 'Plan' => ['Plan Rows' => $value, 'Total Cost' => $value]]],
                500, NAN, -1, -1, -1, -1,
            );
            self::assertNotNull($summary);
            foreach (['planning_ms', 'root_plan_rows', 'root_total_cost', 'decode_elapsed_ms'] as $field) {
                self::assertNull($summary[$field]);
            }
            self::assertSame(0, $summary['plan_bytes']);
            self::assertSame(0, $summary['query_bytes']);
            self::assertSame(0, $summary['bindings_count']);
            self::assertSame(1, $summary['plan_ordinal']);
        }
    }

    public function test_plan_walk_is_bounded_in_width_and_depth(): void
    {
        $wide = array_fill(0, 10000, ['Node Type' => 'SECRET']);
        $summary = (new AssistantStatusSnapshotPlanProfile)->sample($wide, 500, 0, 1, 1, 0, 1);
        self::assertNotNull($summary);
        self::assertSame(4096, $summary['entries_visited']);
        self::assertTrue($summary['truncated']);
        $deep = ['Node Type' => 'SECRET'];
        for ($depth = 0; $depth < 200; $depth++) {
            $deep = [$deep];
        }
        $summary = (new AssistantStatusSnapshotPlanProfile)->sample($deep, 500, 0, 1, 1, 0, 1);
        self::assertNotNull($summary);
        self::assertSame(128, $summary['max_array_depth']);
        self::assertLessThanOrEqual(4096, $summary['entries_visited']);
        self::assertTrue($summary['truncated']);
    }
}
