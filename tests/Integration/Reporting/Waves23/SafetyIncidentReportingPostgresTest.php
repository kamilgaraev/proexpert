<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class SafetyIncidentReportingPostgresTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function site_transition_exposure_and_snapshot_constraints_are_present(): void
    {
        $this->requirePostgres();
        $constraints = collect(DB::select(
            'select conname from pg_constraint where conname in (?, ?, ?, ?)',
            [
                'safety_site_code_unique',
                'safety_transition_event_version_unique',
                'safety_exposure_day_unique',
                'safety_incident_snapshot_frequency_check',
            ],
        ))->pluck('conname')->sort()->values()->all();

        self::assertSame([
            'safety_exposure_day_unique',
            'safety_incident_snapshot_frequency_check',
            'safety_site_code_unique',
            'safety_transition_event_version_unique',
        ], $constraints);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL contract');
        }
    }
}
