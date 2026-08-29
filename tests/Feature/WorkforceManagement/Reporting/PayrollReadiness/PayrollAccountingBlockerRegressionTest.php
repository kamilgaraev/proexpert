<?php

declare(strict_types=1);

namespace Tests\Feature\WorkforceManagement\Reporting\PayrollReadiness;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPeriodIdentity;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Services\PayrollReadinessOwnerSnapshotRecorder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
final class PayrollAccountingBlockerRegressionTest extends TestCase
{
    use RefreshDatabase;

    // Regression: ISSUE-081 — accounting blocker made payroll lock return HTTP 500
    // Found by /qa on 2026-08-29
    // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
    public function test_accounting_blocker_snapshot_is_persisted_without_contract_failure(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $createdAt = $now->modify('-1 minute')->format('Y-m-d H:i:s.uP');

        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'is_owner' => true,
            'is_active' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $employeeId = (int) DB::table('workforce_employees')->insertGetId([
            'organization_id' => $organization->id,
            'personnel_number' => 'ISSUE-081-EMP',
            'last_name' => 'Волков',
            'first_name' => 'Алексей',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $periodId = (int) DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-07',
            'status' => 'validated',
            'created_by_user_id' => $user->id,
            'currency' => 'RUB',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $sourceRowId = (int) DB::table('workforce_payroll_source_rows')->insertGetId([
            'organization_id' => $organization->id,
            'payroll_period_id' => $periodId,
            'employee_id' => $employeeId,
            'project_id' => $project->id,
            'work_date' => '2026-09-01',
            'source_type' => 'timesheet_hours',
            'hours' => 8,
            'amount' => 8000,
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('workforce_payroll_validation_issues')->insert([
            'organization_id' => $organization->id,
            'payroll_period_id' => $periodId,
            'severity' => 'blocking',
            'issue_code' => 'missing_accounting_mapping',
            'message' => 'fixture-only',
            'entity_type' => 'payroll_source_row',
            'entity_id' => $sourceRowId,
            'employee_id' => $employeeId,
            'project_id' => $project->id,
            'payload' => json_encode(['work_date' => '2026-09-01'], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $snapshot = $this->app->make(PayrollReadinessOwnerSnapshotRecorder::class)->recordBlocked(
            new PayrollReadinessPeriodIdentity(
                (int) $organization->id,
                $periodId,
                (int) $project->id,
                '2026-09-01',
                '2026-09-07',
            ),
            (int) $user->id,
            $now,
            str_repeat('a', 64),
            PayrollReadinessReason::ACCOUNTING_BLOCKERS,
        );

        self::assertSame('accounting_blockers', $snapshot->reason->value);
        self::assertSame(1, DB::table('workforce_payroll_readiness_snapshots')->count());
        self::assertSame(1, DB::table('workforce_payroll_readiness_snapshots')->value('blocker_count'));
    }
}
