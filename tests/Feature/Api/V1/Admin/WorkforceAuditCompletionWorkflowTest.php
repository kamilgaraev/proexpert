<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingPolicyService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceCorporateService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceSummaryService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceAuditCompletionWorkflowTest extends TestCase
{
    public function test_payroll_correction_preserves_accepted_package_and_previous_snapshot(): void
    {
        Queue::fake();
        $context = AdminApiTestContext::create();
        $this->allowPermissions();
        $periodId = $this->lockedPeriod($context);
        $packageId = DB::table('workforce_export_packages')->insertGetId([
            'organization_id' => $context->organization->id, 'payroll_period_id' => $periodId,
            'package_number' => 'TEST-ACCEPTED-1', 'status' => 'accepted', 'source_hash' => str_repeat('a', 64),
            'created_by_user_id' => $context->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(WorkforceCorporateService::class);
        $result = $service->reopenPayrollPeriod($context->organization->id, $periodId, $context->user,
            ['expected_source_hash' => str_repeat('a', 64), 'basis_reference' => 'Исправление №ТЕСТ-1 от 14.10.2026']);
        self::assertSame('draft', $result['status']);
        self::assertSame(2, $result['revision']);
        self::assertSame('accepted', DB::table('workforce_export_packages')->where('id', $packageId)->value('status'));
        $archive = $service->payrollRevision($context->organization->id, $periodId, 1, $context->user);
        self::assertSame('locked', $archive['snapshot']['period']['status']);
        self::assertSame(str_repeat('a', 64), $archive['snapshot']['period']['source_hash']);
        self::assertSame(64, strlen($archive['snapshot_sha256']));
    }

    public function test_stale_payroll_correction_does_not_change_locked_period(): void
    {
        Queue::fake();
        $context = AdminApiTestContext::create();
        $this->allowPermissions();
        $periodId = $this->lockedPeriod($context);
        try {
            app(WorkforceCorporateService::class)->reopenPayrollPeriod($context->organization->id, $periodId, $context->user,
                ['expected_source_hash' => str_repeat('b', 64), 'basis_reference' => 'Исправление ТЕСТ']);
            self::fail('Stale snapshot must not be reopened');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.payroll_source_changed'), $exception->getMessage());
        }
        self::assertSame('locked', DB::table('workforce_payroll_periods')->where('id', $periodId)->value('status'));
        self::assertSame(0, DB::table('workforce_payroll_revisions')->count());
    }

    public function test_pending_export_prevents_payroll_correction(): void
    {
        Queue::fake();
        $context = AdminApiTestContext::create();
        $this->allowPermissions();
        $periodId = $this->lockedPeriod($context);
        DB::table('workforce_export_packages')->insert([
            'organization_id' => $context->organization->id, 'payroll_period_id' => $periodId, 'package_number' => 'TEST-SENT',
            'status' => 'sent', 'source_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('workforce.errors.payroll_export_pending'));
        app(WorkforceCorporateService::class)->reopenPayrollPeriod($context->organization->id, $periodId, $context->user,
            ['expected_source_hash' => str_repeat('a', 64), 'basis_reference' => 'Исправление ТЕСТ']);
    }

    public function test_absence_approval_cannot_change_locked_payroll_sources(): void
    {
        Queue::fake();
        $context = AdminApiTestContext::create();
        $this->allowPermissions();
        $this->lockedPeriod($context);
        $employeeId = DB::table('workforce_employees')->insertGetId(['organization_id' => $context->organization->id,
            'personnel_number' => 'TEST-ABSENCE', 'first_name' => 'Тест', 'last_name' => 'Сотрудник', 'employment_status' => 'active', 'hire_date' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);
        $absenceId = app(WorkforceProService::class)->storeAbsence($context->organization->id, [
            'employee_id' => $employeeId, 'absence_type_code' => 'vacation', 'reason_code' => 'vacation', 'basis_reference' => 'Приказ ТЕСТ',
            'start_date' => '2026-10-15', 'end_date' => '2026-10-16',
        ], $context->user)['id'];
        try {
            app(WorkforceProService::class)->approveAbsence($context->organization->id, $absenceId, $context->user);
            self::fail('Locked payroll source must not change');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.payroll_period_locked'), $exception->getMessage());
        }
        self::assertSame('draft', DB::table('workforce_absences')->where('id', $absenceId)->value('status'));
    }

    public function test_schedule_exception_cannot_change_a_frozen_period_but_future_days_remain_editable(): void
    {
        Queue::fake();
        $context = AdminApiTestContext::create();
        $this->allowPermissions();
        $this->lockedPeriod($context);
        $service = app(WorkforceProService::class);
        $scheduleId = $service->store('workforce_work_schedules', $context->organization->id, [
            'code' => 'FROZEN-TEST', 'name' => 'Тестовый график', 'schedule_type' => 'weekly', 'hours_per_day' => 8,
        ])['id'];
        try {
            $service->storeScheduleDay($context->organization->id, $scheduleId, ['work_date' => '2026-10-15', 'day_type' => 'off', 'planned_hours' => 0]);
            self::fail('A dated plan must not change the frozen payroll source');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.payroll_period_locked'), $exception->getMessage());
        }
        self::assertSame(0, DB::table('workforce_work_schedule_days')->where('work_schedule_id', $scheduleId)->count());
        $result = $service->storeScheduleDay($context->organization->id, $scheduleId, ['work_date' => '2026-11-15', 'day_type' => 'off', 'planned_hours' => 0]);
        self::assertSame('2026-11-15', $result['work_date']);
    }

    public function test_module_summary_omits_payroll_and_exports_without_read_permissions(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowPermissions(['workforce.payroll-source.view', 'workforce.exports.view']);
        $this->lockedPeriod($context);
        $summary = app(WorkforceSummaryService::class)->summary($context->organization->id, $context->user);
        self::assertArrayNotHasKey('payroll_periods', $summary);
        self::assertArrayNotHasKey('export_packages', $summary);
        self::assertSame(0, $summary['employees']['total']);
    }

    public function test_policy_is_versioned_and_calendar_replacement_does_not_keep_old_dates(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowPermissions();
        config(['workforce.timekeeping.calendars.RU.2026' => ['version' => 'default', 'source' => 'Федеральный календарь',
            'holidays' => ['2026-01-01', '2026-01-02'], 'shortened_days' => [], 'working_days' => []]]);
        $service = app(TimekeepingPolicyService::class);
        $settings = ['form' => ['version' => 'TEST-1', 'approved_basis_reference' => 'Приказ ТЕСТ', 'approved_at' => '2026-01-01'],
            'retention' => ['approved_basis_reference' => 'Положение ТЕСТ'], 'legal_review_required' => true,
            'calendars' => ['RU' => ['2026' => ['version' => 'region-1', 'source' => 'Региональный акт ТЕСТ',
                'holidays' => ['2026-01-01'], 'shortened_days' => [], 'working_days' => []]]]];
        $result = $service->update($context->organization->id, $context->user, ['expected_revision' => 0, 'settings' => $settings]);
        self::assertSame(1, $result['revision']);
        self::assertSame(['2026-01-01'], $service->forOrganization($context->organization->id)['calendars']['RU']['2026']['holidays']);
        $wrongYear = $settings;
        $wrongYear['calendars']['RU']['2026']['holidays'] = ['2027-06-12'];
        try {
            $service->update($context->organization->id, $context->user, ['expected_revision' => 1, 'settings' => $wrongYear]);
            self::fail('A calendar date from another year must not replace the approved calendar');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('settings.calendars.RU.2026.holidays.0', $exception->errors());
        }
        self::assertSame(1, $service->show($context->organization->id, $context->user)['revision']);
        $this->expectException(DomainException::class);
        $service->update($context->organization->id, $context->user, ['expected_revision' => 0, 'settings' => $settings]);
    }

    public function test_event_basis_uses_hr_document_id_and_rejects_another_employees_document(): void
    {
        Queue::fake();
        $context = AdminApiTestContext::create();
        $this->allowPermissions();
        $employee = WorkforceEmployee::create(['organization_id' => $context->organization->id, 'personnel_number' => 'BASIS-1',
            'last_name' => 'Тест', 'first_name' => 'Сотрудник', 'employment_status' => 'active', 'hire_date' => '2026-05-01']);
        $file = File::create(['organization_id' => $context->organization->id, 'user_id' => $context->user->id,
            'fileable_type' => WorkforceEmployee::class, 'fileable_id' => $employee->id, 'name' => 'basis.pdf', 'original_name' => 'basis.pdf',
            'path' => 'test/workforce/basis.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'disk' => 's3', 'type' => 'document',
            'category' => 'workforce_hr', 'additional_info' => ['visibility' => 'private']]);
        $documentId = (int) $file->id + 100000;
        DB::table('workforce_hr_documents')->insert(['id' => $documentId, 'organization_id' => $context->organization->id,
            'employee_id' => $employee->id, 'document_type' => 'personnel_order', 'title' => 'Основание ТЕСТ', 'file_id' => $file->id,
            'retention_class' => 'unclassified_hold', 'document_date' => '2026-05-01', 'retention_policy_metadata' => '{}',
            'recorded_by' => $context->user->id, 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['employee_id' => $employee->id, 'absence_type_code' => 'vacation', 'basis_reference' => 'Приказ ТЕСТ',
            'basis_document_id' => $documentId, 'start_date' => '2026-05-15', 'end_date' => '2026-05-16'];
        $record = app(WorkforceProService::class)->storeAbsence($context->organization->id, $payload, $context->user);
        self::assertSame($documentId, (int) $record['basis_document_id']);
        self::assertNotSame((int) $file->id, $documentId);
        $other = WorkforceEmployee::create(['organization_id' => $context->organization->id, 'personnel_number' => 'BASIS-2',
            'last_name' => 'Другой', 'first_name' => 'Сотрудник', 'employment_status' => 'active', 'hire_date' => '2026-05-01']);
        $payload['employee_id'] = $other->id;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('workforce.errors.hr_file_invalid'));
        app(WorkforceProService::class)->storeAbsence($context->organization->id, $payload, $context->user);
    }

    public function test_new_open_ended_assignment_cannot_change_a_later_locked_period(): void
    {
        Queue::fake();
        $context = AdminApiTestContext::create();
        $this->allowPermissions();
        $this->lockedPeriod($context);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('workforce.errors.payroll_period_locked'));
        app(WorkforceProService::class)->storeAssignment($context->organization->id,
            ['employee_id' => 0, 'valid_from' => '2026-05-01', 'basis_reference' => 'Назначение ТЕСТ'], null, $context->user);
    }

    private function allowPermissions(array $denied = []): void
    {
        $this->mock(AuthorizationService::class, function ($mock) use ($denied): void {
            $mock->shouldReceive('canCurrent')->andReturnUsing(fn ($user, string $permission): bool => ! in_array($permission, $denied, true));
        });
    }

    private function lockedPeriod(AdminApiTestContext $context): int
    {
        return DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $context->organization->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'status' => 'locked', 'source_hash' => str_repeat('a', 64), 'locked_at' => now(),
            'created_by_user_id' => $context->user->id, 'locked_by_user_id' => $context->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
