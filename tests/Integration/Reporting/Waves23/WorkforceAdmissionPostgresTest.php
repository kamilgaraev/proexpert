<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\ProductionReportScopedResourceAuthorizers;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\SafetyEvidenceVersionResolver;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    #[Test]
    public function admission_row_persists_the_real_site_mapping_primary_key(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $employeeId = DB::table('workforce_employees')->insertGetId([
            'organization_id' => $organization->id,
            'personnel_number' => 'MAP-'.Str::random(8),
            'last_name' => 'Тестов',
            'first_name' => 'Сотрудник',
            'employment_status' => 'active',
            'hire_date' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [$departmentId, $positionId, $staffUnitId] = $this->structure((int) $organization->id);
        $assignmentId = DB::table('workforce_employee_assignments')->insertGetId([
            'organization_id' => $organization->id,
            'employee_id' => $employeeId,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'project_id' => $project->id,
            'valid_from' => '2026-07-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $siteId = DB::table('safety_sites')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'code' => 'SITE-'.Str::random(8),
            'name' => 'Площадка',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'active_from' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mappingId = DB::table('safety_site_workforce_assignments')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'safety_site_id' => $siteId,
            'workforce_assignment_id' => $assignmentId,
            'employee_id' => $employeeId,
            'valid_from' => '2026-07-01',
            'mapping_source' => 'workforce_employee_assignments',
            'source_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $snapshotId = (string) Str::ulid();
        DB::table('safety_admission_snapshots')->insert([
            'id' => $snapshotId,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'safety_site_id' => $siteId,
            'policy_version_ids' => '[]',
            'scope_hash' => str_repeat('a', 64),
            'definition_hash' => str_repeat('b', 64),
            'formula_version' => 'test',
            'query_hash' => str_repeat('c', 64),
            'input_hash' => str_repeat('d', 64),
            'output_hash' => str_repeat('e', 64),
            'source_hash' => str_repeat('f', 64),
            'snapshot_date' => '2026-07-30',
            'source_watermark' => now(),
            'source_ledger_binding' => '{}',
            'generated_at' => now(),
            'stale_at' => now()->addMinute(),
        ]);
        $rowId = DB::table('safety_admission_rows')->insertGetId([
            'organization_id' => $organization->id,
            'snapshot_id' => $snapshotId,
            'project_id' => $project->id,
            'safety_site_id' => $siteId,
            'site_assignment_id' => $mappingId,
            'workforce_assignment_id' => $assignmentId,
            'employee_id' => $employeeId,
            'snapshot_date' => '2026-07-30',
            'row_type' => 'requirement',
            'row_key' => 'mapping-'.$mappingId,
            'requirement_code' => 'training',
            'requirement_type' => 'training',
            'status' => 'missing',
            'mandatory' => true,
            'blocked' => true,
            'verified' => false,
            'blocker_codes' => '[]',
        ]);

        self::assertSame($mappingId, (int) DB::table('safety_admission_rows')->find($rowId)->site_assignment_id);

        $trainingId = DB::table('safety_training_records')->insertGetId([
            'organization_id' => $organization->id,
            'employee_id' => $employeeId,
            'program_code' => 'training',
            'program_name' => 'Обязательное обучение',
            'training_type' => 'mandatory',
            'completed_at' => '2026-07-29',
            'valid_until' => now()->addYear()->toDateString(),
            'result' => 'passed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $evidenceVersion = DB::table('safety_evidence_versions')
            ->where('evidence_type', 'training')
            ->where('evidence_id', $trainingId)
            ->latest('id')
            ->first();
        self::assertNotNull($evidenceVersion);
        $evidenceIdentity = [
            'evidence_id' => $trainingId,
            'evidence_type' => 'training',
            'employee_id' => $employeeId,
            'project_id' => (int) $project->id,
            'safety_site_id' => $siteId,
            'site_assignment_id' => $mappingId,
            'version_hash' => (string) $evidenceVersion->content_hash,
            'version_id' => (int) $evidenceVersion->id,
            'workforce_assignment_id' => $assignmentId,
        ];
        $evidenceCutoff = CarbonImmutable::now();
        $evidenceRowId = DB::table('safety_admission_rows')->insertGetId([
            'organization_id' => $organization->id,
            'snapshot_id' => $snapshotId,
            'project_id' => $project->id,
            'safety_site_id' => $siteId,
            'site_assignment_id' => $mappingId,
            'workforce_assignment_id' => $assignmentId,
            'employee_id' => $employeeId,
            'snapshot_date' => '2026-07-30',
            'row_type' => 'requirement',
            'row_key' => 'mapping-evidence-'.$mappingId,
            'requirement_code' => 'training',
            'requirement_type' => 'training',
            'status' => 'fulfilled',
            'mandatory' => true,
            'blocked' => false,
            'verified' => true,
            'evidence_type' => 'training',
            'evidence_id' => $trainingId,
            'evidence_version_id' => $evidenceVersion->id,
            'evidence_hash' => $evidenceVersion->content_hash,
            'evidence_identity' => json_encode($evidenceIdentity, JSON_THROW_ON_ERROR),
            'blocker_codes' => '[]',
        ]);
        $resource = new ReportScopedResource('workforce_snapshot_evidence', $evidenceRowId, (int) $project->id);
        $facts = new CurrentReportAuthorizationFacts(
            'queue',
            (int) $actor->id,
            (int) $organization->id,
            (int) $project->id,
            $resource,
            new DateTimeImmutable,
        );
        $authorizer = collect((new ProductionReportScopedResourceAuthorizers)->handlers())
            ->first(static fn ($handler): bool => $handler->kind() === 'workforce_snapshot_evidence');
        self::assertNotNull($authorizer);
        self::assertTrue($authorizer->authorize($actor, (int) $organization->id, $resource, $facts)->granted);

        DB::table('safety_training_records')->where('id', $trainingId)->update(['result' => 'failed']);

        self::assertTrue($authorizer->authorize($actor, (int) $organization->id, $resource, $facts)->granted);
        $temporal = app(SafetyEvidenceVersionResolver::class)->requirement(
            (int) $organization->id,
            $employeeId,
            ['code' => 'training', 'type' => 'training', 'label' => 'Обучение', 'required' => true],
            CarbonImmutable::parse('2026-07-30'),
            $evidenceCutoff,
            (int) $project->id,
        );
        self::assertSame('fulfilled', $temporal->status);
        self::assertNotSame(
            (string) $evidenceVersion->content_hash,
            (string) DB::table('safety_evidence_versions')->latest('id')->value('content_hash'),
        );
        foreach ([
            ['safety_admission_rows', $evidenceRowId, ['status' => 'missing']],
            ['safety_admission_snapshots', $snapshotId, ['output_hash' => str_repeat('0', 64)]],
        ] as [$table, $id, $mutation]) {
            try {
                DB::table($table)->where('id', $id)->update($mutation);
                self::fail('Sealed reporting record accepted mutation');
            } catch (QueryException $exception) {
                self::assertSame('55000', $exception->getCode());
            }
        }
    }

    private function structure(int $organizationId): array
    {
        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'DEP-'.Str::random(8),
            'name' => 'Участок',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'POS-'.Str::random(8),
            'name' => 'Монтажник',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staffUnitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'UNIT-'.Str::random(8),
            'headcount' => 1,
            'rate' => 1,
            'valid_from' => '2026-07-01',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$departmentId, $positionId, $staffUnitId];
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL contract');
        }
    }
}
