<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceCapacityOwnerCreationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_staff_unit_creation_returns_the_persisted_capacity_owner(): void
    {
        // Regression: ISSUE-076 — создание штатной единицы завершалось HTTP 500
        // Found by /qa on 2026-08-29
        // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
        $context = AdminApiTestContext::create();
        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'QA-DEPARTMENT',
            'name' => 'Производственный участок',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'QA-POSITION',
            'name' => 'Производитель работ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = app(WorkforceProService::class)->storeStaffUnit($context->organization->id, [
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'QA-STAFF-UNIT',
            'headcount' => 2,
            'rate' => 850,
            'base_salary' => 150000,
            'valid_from' => '2026-08-01',
        ]);

        self::assertSame('QA-STAFF-UNIT', $record['code']);
        $this->assertDatabaseHas('workforce_staff_units', [
            'organization_id' => $context->organization->id,
            'code' => 'QA-STAFF-UNIT',
        ]);
    }

    public function test_work_schedule_creation_returns_the_persisted_capacity_owner(): void
    {
        // Regression: ISSUE-076 — создание рабочего графика завершалось HTTP 500
        // Found by /qa on 2026-08-29
        // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
        $context = AdminApiTestContext::create();

        $record = app(WorkforceProService::class)->store('workforce_work_schedules', $context->organization->id, [
            'code' => 'QA-SCHEDULE',
            'name' => 'Пятидневная рабочая неделя',
            'schedule_type' => 'five_two',
            'hours_per_day' => 8,
        ]);

        self::assertSame('QA-SCHEDULE', $record['code']);
        $this->assertDatabaseHas('workforce_work_schedules', [
            'organization_id' => $context->organization->id,
            'code' => 'QA-SCHEDULE',
        ]);
    }
}
