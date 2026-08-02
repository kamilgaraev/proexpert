<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Support\Facades\DB;

final class ProcurementCyclePostgresTest extends Waves23PostgresTestCase
{
    public function test_event_identity_state_and_cohort_schema_are_database_fenced(): void
    {
        $this->assertTriggerExists('procurement_process_events', 'procurement_process_events_append_only');
        $this->assertTriggerExists(
            'procurement_cycle_owner_expectation_versions',
            'procurement_cycle_owner_expectation_versions_append_only',
        );
        self::assertNotNull($this->column('procurement_cycle_owner_expectation_versions', 'dimensions'));
        self::assertNotNull($this->column('procurement_cycle_rows', 'process_event_ids'));
        self::assertSame('boolean', $this->column('procurement_cycle_rows', 'cohort_mature')->data_type);
        self::assertSame('date', $this->column('procurement_cycle_rows', 'outcome_cohort_date')->data_type);
        self::assertTrue(DB::table('pg_constraint')
            ->where('conname', 'proc_cycle_event_code_check')
            ->exists());
        self::assertTrue(DB::table('pg_indexes')
            ->where('indexname', 'proc_cycle_expectation_version_unique')
            ->exists());
        self::assertTrue(DB::table('pg_indexes')
            ->where('indexname', 'proc_cycle_expectation_source_unique')
            ->exists());
    }
}
