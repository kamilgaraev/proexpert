<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceExecutionFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\WorkforceCapacityFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceAttendanceReportSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL and real temporal owner-fact projections.');
        }
    }

    public function test_schedule_only_has_plan_but_unknown_actual_duration(): void
    {
        $fixture = $this->fixture();
        [$page, $result] = $this->read($fixture);
        $row = $page->rows[0];

        self::assertSame('8.00', $row['eligible_hours']);
        self::assertSame('fact_unconfirmed', $row['status']);
        self::assertFalse($row['actual_duration_known']);
        self::assertNull($row['confirmed_hours']);
        self::assertNull($row['execution_percent']);
        self::assertFalse($row['violation']);
        // JSONB canonicalizes object-key order; lineage identity is the type and exact source ID.
        self::assertContainsEquals(['type' => 'schedule_day', 'id' => $fixture['schedule_day_id']], $row['source_refs']);
        $this->assertUnknownTotals($page, $result);
    }

    public function test_single_qr_scan_confirms_presence_without_inventing_duration_from_metadata_or_plan(): void
    {
        $fixture = $this->fixture();
        $scanId = DB::table('workforce_attendance_scan_events')->insertGetId([
            'organization_id' => $fixture['organization_id'], 'employee_id' => $fixture['employee_id'],
            'scanned_by_user_id' => $fixture['actor_id'], 'work_date' => '2026-09-02', 'result' => 'confirmed',
            'result_label' => 'Присутствие подтверждено', 'scanned_at' => '2026-09-02 09:00:00+03:00',
            'metadata' => json_encode(['present_hours' => 16], JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-02 09:00:00+03:00', 'updated_at' => '2026-09-02 09:00:00+03:00',
        ]);
        [$page, $result] = $this->read($fixture);
        $row = $page->rows[0];

        self::assertSame('presence_duration_unconfirmed', $row['status']);
        self::assertFalse($row['actual_duration_known']);
        self::assertNull($row['confirmed_hours']);
        self::assertSame('8.00', $row['eligible_hours']);
        self::assertContainsEquals(['type' => 'attendance_scan', 'id' => $scanId], $row['source_refs']);
        $this->assertUnknownTotals($page, $result);
    }

    public function test_quantified_correction_preserves_actual_hours_above_capped_coverage_in_rows_and_totals(): void
    {
        $fixture = $this->fixture();
        $correctionId = DB::table('workforce_attendance_corrections')->insertGetId([
            'organization_id' => $fixture['organization_id'], 'employee_id' => $fixture['employee_id'],
            'work_date' => '2026-09-02', 'status' => 'at_work', 'hours' => 12,
            'reason' => 'Подтверждённая ручная сверка часов', 'created_by_user_id' => $fixture['actor_id'],
            'created_at' => '2026-09-02 21:00:00+03:00', 'updated_at' => '2026-09-02 21:00:00+03:00',
        ]);
        [$page, $result] = $this->read($fixture);
        $row = $page->rows[0];

        self::assertTrue($row['actual_duration_known']);
        self::assertEquals(12, $row['confirmed_hours']);
        // Legacy present_hours is capped coverage for execution; confirmed_hours is the actual duration.
        self::assertSame('8.00', $row['present_hours']);
        self::assertNotEquals($row['present_hours'], $row['confirmed_hours']);
        self::assertSame('4.00', $row['overtime_hours']);
        self::assertSame('100.00', $row['execution_percent']);
        self::assertSame('covered', $row['status']);
        self::assertContainsEquals(['type' => 'attendance_correction', 'id' => $correctionId], $row['audit_refs']);
        self::assertSame('12.00', $result->totals['confirmed_hours']);
        self::assertSame('8.00', $result->totals['present_hours']);
        self::assertSame('4.00', $result->totals['overtime_hours']);
        self::assertTrue($page->totals['actual_duration_known']);
        self::assertNotContains('ATTENDANCE_DURATION_UNCONFIRMED', array_column($result->quality->warnings, 'code'));
    }

    private function assertUnknownTotals(ReportPage $page, ReportResult $result): void
    {
        self::assertFalse($page->totals['actual_duration_known']);
        self::assertSame(1, $page->totals['unknown_duration_rows']);
        self::assertNull($page->totals['confirmed_hours']);
        self::assertNull($page->totals['execution_percent']);
        self::assertNull($result->totals['confirmed_hours']);
        self::assertContains('ATTENDANCE_DURATION_UNCONFIRMED', array_column($result->quality->warnings, 'code'));
        self::assertContains('confirmed_hours', array_column($result->rowSchema, 'id'));
        self::assertNotContains('present_hours', array_column($result->rowSchema, 'id'));
    }

    private function read(array $fixture): array
    {
        $zone = new DateTimeZone('Europe/Moscow');
        $scope = new ReportScope($fixture['organization_id'], [$fixture['organization_id']], [], [], $zone);
        // PostgreSQL temporal columns round to whole seconds; wait until all recorded times are real past.
        DB::select('SELECT pg_sleep(1)');
        // Owner-fact triggers use PostgreSQL clock_timestamp(), not the PHP host clock.
        $cutoff = new DateTimeImmutable((string) DB::selectOne('SELECT clock_timestamp() AS cutoff')->cutoff);
        $scheduleFact = DB::table('workforce_report_owner_facts')->where('organization_id', $fixture['organization_id'])
            ->where('source_table', 'workforce_work_schedule_days')->where('source_id', $fixture['schedule_day_id'])->first();
        self::assertNotNull($scheduleFact);
        self::assertLessThanOrEqual($cutoff, new DateTimeImmutable($scheduleFact->recorded_at));
        $latestEligibility = DB::table('workforce_report_owner_fact_eligibility')->where('organization_id', $fixture['organization_id'])->max('eligible_from');
        self::assertNotNull($latestEligibility);
        self::assertLessThanOrEqual($cutoff, new DateTimeImmutable($latestEligibility));
        $query = new ReportQuery(app(AttendanceExecutionBuiltinPublishedReport::class)->definition()->definition, $scope,
            new ReportFilterSet(['day_from' => '2026-09-02', 'day_to' => '2026-09-02']), [], $cutoff, 'ru');
        $context = new ReportExecutionContext(new ReportActor($fixture['actor_id'], 'active', ['workforce.view', 'workforce.audit.view']), $scope,
            new ReportVisibility(true, true, true, true, false, true, true),
            new AuthorizationDecisionContext('cli', $fixture['organization_id'], [$fixture['organization_id']], [], [], $zone, 'attendance-source-regression', null));
        $adapter = new DatabaseWorkforceReportAdapter(DB::connection(), new WorkforceCapacityFormula, new AttendanceExecutionFormula);
        $snapshot = $adapter->materializeAttendance($scope, $query);
        $page = $adapter->page($context, $snapshot, new ReportWindowSort('work_date', ReportSortDirection::ASC), null, 10);
        self::assertCount(1, $page->rows);
        self::assertSame($fixture['employee_id'], $page->rows[0]['employee_id']);
        self::assertSame(1, DB::table('attendance_execution_snapshot_rows')->where('snapshot_id', $snapshot->id)->count());

        return [$page, $adapter->result($context, $snapshot)];
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create();
        $org = (int) $context->organization->id;
        $timestamps = ['created_at' => '2026-09-01 00:00:00+03:00', 'updated_at' => '2026-09-01 00:00:00+03:00'];
        $employee = WorkforceEmployee::query()->create(['organization_id' => $org, 'personnel_number' => 'REPORT-F01', 'first_name' => 'Иван', 'last_name' => 'Петров', 'hire_date' => '2026-09-01', 'employment_status' => 'active']);
        $department = DB::table('workforce_departments')->insertGetId(['organization_id' => $org, 'code' => 'REPORT', 'name' => 'Участок'] + $timestamps);
        $position = DB::table('workforce_positions')->insertGetId(['organization_id' => $org, 'code' => 'INSTALLER', 'name' => 'Монтажник'] + $timestamps);
        $schedule = DB::table('workforce_work_schedules')->insertGetId(['organization_id' => $org, 'code' => 'DAY', 'name' => 'Дневная смена', 'schedule_type' => 'weekly', 'hours_per_day' => 8] + $timestamps);
        $staffUnit = DB::table('workforce_staff_units')->insertGetId(['organization_id' => $org, 'department_id' => $department, 'position_id' => $position, 'code' => 'REPORT-UNIT', 'valid_from' => '2026-09-01'] + $timestamps);
        DB::table('workforce_employee_assignments')->insert(['organization_id' => $org, 'employee_id' => $employee->id, 'department_id' => $department, 'position_id' => $position, 'staff_unit_id' => $staffUnit, 'work_schedule_id' => $schedule, 'valid_from' => '2026-09-01', 'status' => 'active', 'rate' => 1] + $timestamps);
        $day = DB::table('workforce_work_schedule_days')->insertGetId(['organization_id' => $org, 'work_schedule_id' => $schedule, 'work_date' => '2026-09-02', 'day_type' => 'work', 'planned_hours' => 8] + $timestamps);

        return ['organization_id' => $org, 'actor_id' => (int) $context->user->id, 'employee_id' => (int) $employee->id, 'schedule_day_id' => $day];
    }
}
