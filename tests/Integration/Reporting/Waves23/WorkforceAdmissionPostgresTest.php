<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use App\Models\Organization;
use Carbon\CarbonImmutable;
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
    public function assignment_policy_and_requirement_grain_constraints_are_present(): void
    {
        $this->requirePostgres();
        $constraints = collect(DB::select(
            'select conname from pg_constraint where conname in (?, ?, ?, ?, ?)',
            [
                'safety_site_workforce_assignment_no_overlap',
                'safety_admission_snapshot_counts_check',
                'safety_admission_row_type_check',
                'safety_site_workforce_assignment_source_check',
                'safety_admission_policy_no_overlap',
            ],
        ))->pluck('conname')->sort()->values()->all();

        self::assertSame([
            'safety_admission_row_type_check',
            'safety_admission_policy_no_overlap',
            'safety_admission_snapshot_counts_check',
            'safety_site_workforce_assignment_no_overlap',
            'safety_site_workforce_assignment_source_check',
        ], $constraints);

        $triggers = collect(DB::select(
            "select tgname from pg_trigger where not tgisinternal and tgname in ('safety_site_workforce_assignments_immutable', 'safety_admission_policies_immutable')",
        ))->pluck('tgname')->sort()->values()->all();
        self::assertSame([
            'safety_admission_policies_immutable',
            'safety_site_workforce_assignments_immutable',
        ], $triggers);
    }

    #[Test]
    public function pinned_compliance_does_not_use_evidence_created_after_as_of(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $asOf = CarbonImmutable::parse('2026-07-26T12:00:00+00:00');
        $employeeId = DB::table('workforce_employees')->insertGetId([
            'organization_id' => $organization->id,
            'personnel_number' => 'ASOF-001',
            'last_name' => 'Тестов',
            'first_name' => 'Сотрудник',
            'employment_status' => 'inactive',
            'hire_date' => '2026-07-01',
            'dismissal_date' => '2026-07-27',
            'created_at' => $asOf->subDay(),
            'updated_at' => $asOf->subDay(),
        ]);
        DB::table('safety_training_records')->insert([
            'organization_id' => $organization->id,
            'employee_id' => $employeeId,
            'program_code' => 'height_work',
            'program_name' => 'Работы на высоте',
            'training_type' => 'mandatory',
            'completed_at' => '2026-07-25',
            'valid_until' => '2027-07-25',
            'result' => 'passed',
            'created_at' => $asOf->addHour(),
            'updated_at' => $asOf->addHour(),
        ]);
        $context = new SafetyComplianceContext(
            organizationId: (int) $organization->id,
            employeeId: (int) $employeeId,
            date: $asOf->startOfDay(),
            evidenceCutoff: $asOf,
        );
        $service = app(SafetyComplianceService::class);

        $results = $service->checkPinnedRequirements($context, [[
            'code' => 'height_work',
            'type' => 'training',
            'mandatory' => true,
        ]]);

        self::assertSame('missing', $results[0]->status);
        self::assertSame([], $service->pinnedLifecycleFlags($context));
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL contract');
        }
    }
}
