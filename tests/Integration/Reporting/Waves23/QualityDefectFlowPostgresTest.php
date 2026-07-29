<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class QualityDefectFlowPostgresTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function transition_and_projection_constraints_are_present(): void
    {
        $this->requirePostgres();
        $constraints = collect(DB::select(
            'select conname from pg_constraint where conname in (?, ?, ?, ?, ?, ?)',
            [
                'quality_defect_transition_version_unique',
                'quality_defect_flow_row_unique',
                'quality_defect_flow_snapshot_counts_check',
                'quality_defect_flow_snapshot_due_check',
                'quality_defect_flow_snapshot_mature_check',
                'quality_defect_flow_policy_no_overlap',
            ],
        ))->pluck('conname')->sort()->values()->all();

        self::assertSame([
            'quality_defect_flow_policy_no_overlap',
            'quality_defect_flow_row_unique',
            'quality_defect_flow_snapshot_counts_check',
            'quality_defect_flow_snapshot_due_check',
            'quality_defect_flow_snapshot_mature_check',
            'quality_defect_transition_version_unique',
        ], $constraints);

        $triggers = collect(DB::select(
            "select tgname from pg_trigger where not tgisinternal and tgname in ('quality_defect_transition_events_immutable', 'quality_defect_flow_policies_immutable')",
        ))->pluck('tgname')->sort()->values()->all();
        self::assertSame([
            'quality_defect_flow_policies_immutable',
            'quality_defect_transition_events_immutable',
        ], $triggers);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL contract');
        }
    }
}
