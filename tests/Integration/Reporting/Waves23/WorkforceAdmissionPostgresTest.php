<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class WorkforceAdmissionPostgresTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function assignment_overlap_and_person_summary_constraints_are_present(): void
    {
        $this->requirePostgres();
        $constraints = collect(DB::select(
            'select conname from pg_constraint where conname in (?, ?, ?)',
            [
                'safety_site_workforce_assignment_no_overlap',
                'safety_admission_snapshot_counts_check',
                'safety_admission_row_type_check',
            ],
        ))->pluck('conname')->sort()->values()->all();

        self::assertSame([
            'safety_admission_row_type_check',
            'safety_admission_snapshot_counts_check',
            'safety_site_workforce_assignment_no_overlap',
        ], $constraints);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL contract');
        }
    }
}
