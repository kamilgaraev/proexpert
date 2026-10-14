<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\BusinessModules\Features\ProductionLabor\Services\ProductionLaborService;
use App\BusinessModules\Features\TimeTracking\Services\MobileTimeTrackingService;
use App\BusinessModules\Features\TimeTracking\Services\TimeEntryImportService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\UserProjectAccessMode;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeTrackingService;
use App\Services\WorkforcePeriodMutationGuard;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class TimeTrackingClosedPeriodWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_month_denies_new_fact_and_source_approval(): void
    {
        [$context, $project] = $this->context();
        $entry = TimeEntry::create($this->fact($context, $project) + ['status' => 'submitted']);
        $this->period($context, $project);
        $this->denied(fn () => app(TimeTrackingService::class)->approveTimeEntry((int) $entry->id, $context->user));
        self::assertSame('submitted', $entry->refresh()->status);
        $this->denied(fn () => app(TimeTrackingService::class)->createTimeEntry($this->fact($context, $project)));
        self::assertDatabaseCount('time_entries', 1);
    }

    public function test_confirmed_civil_only_fact_is_outside_tk_freeze_but_locked_payroll_still_blocks(): void
    {
        [$context, $project] = $this->context();
        EmploymentContract::query()->where('organization_id', $context->organization->id)->update([
            'relation_type' => 'civil', 'employment_mode' => null, 'job_share' => null,
            'weekly_norm_hours' => null, 'summed_accounting_period' => null, 'calendar_profile' => null,
        ]);
        $this->period($context, null);
        $service = app(TimeTrackingService::class);
        $civil = $service->createTimeEntry($this->fact($context, $project));
        self::assertSame('draft', $civil->status);
        DB::table('workforce_payroll_periods')->insert([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'status' => 'locked',
        ]);
        $this->denied(fn () => $service->updateTimeEntry((int) $civil->id, ['title' => 'Payroll alteration']), 'workforce.errors.payroll_period_locked');
        self::assertSame('Measured fact', $civil->refresh()->title);
    }

    public function test_stop_timer_rechecks_closed_month_without_losing_running_state(): void
    {
        [$context, $project] = $this->context();
        $entry = app(MobileTimeTrackingService::class)->startTimer((int) $context->organization->id, $context->user, [
            'project_id' => $project->id, 'work_date' => '2026-05-31', 'start_at' => '2026-05-31T20:00:00Z', 'timezone' => 'UTC', 'title' => 'Timer', 'is_billable' => true,
        ]);
        $this->period($context, $project);
        $this->denied(fn () => app(MobileTimeTrackingService::class)->stopTimer($entry, (int) $context->user->id, ['end_at' => '2026-05-31T21:00:00Z']));
        self::assertNull($entry->refresh()->end_at);
        self::assertTrue($entry->custom_fields['mobile_time_tracking']['active_timer']);
    }

    public function test_civil_contract_first_day_is_not_blocked_by_previous_tk_month_but_legacy_candidate_keeps_guard(): void
    {
        [$context, $project] = $this->context();
        $civilRelation = EmploymentContract::query()->where('organization_id', $context->organization->id)->firstOrFail();
        $civilRelation->update([
            'relation_type' => 'civil', 'start_date' => '2026-05-01', 'end_date' => '2026-05-01',
            'employment_mode' => null, 'job_share' => null, 'weekly_norm_hours' => null,
            'summed_accounting_period' => null, 'calendar_profile' => null,
        ]);
        $this->period($context, null, 'closed', null, '2026-04-01');
        $fact = $this->fact($context, $project);
        foreach (['start_at', 'end_at', 'start_time', 'end_time', 'timezone', 'net_microseconds', 'net_minutes'] as $field) {
            unset($fact[$field]);
        }
        $fact['work_date'] = '2026-05-01';
        $service = app(TimeTrackingService::class);
        $entry = $service->createTimeEntry($fact);
        self::assertSame('2026-05-01', $entry->work_date->format('Y-m-d'));
        self::assertSame('draft', $entry->status);

        EmploymentContract::create([
            'organization_id' => $context->organization->id, 'employee_id' => $civilRelation->employee_id,
            'contract_number' => 'UNKNOWN-BOUNDARY', 'contract_date' => '2026-04-30',
            'start_date' => '2026-04-30', 'end_date' => '2026-04-30', 'status' => 'closed', 'relation_type' => null,
        ]);
        $this->denied(fn () => $service->createTimeEntry($fact));
        self::assertDatabaseCount('time_entries', 1);
    }

    public function test_reopened_successor_allows_correction_but_newly_approved_successor_blocks_again(): void
    {
        [$context, $project] = $this->context();
        $entry = TimeEntry::create($this->fact($context, $project) + ['status' => 'rejected']);
        $closed = $this->period($context, $project);
        $this->denied(fn () => app(TimeTrackingService::class)->updateTimeEntry((int) $entry->id, ['title' => 'Corrected']));
        $successor = $this->period($context, $project, 'draft', (int) $closed->id);
        self::assertSame('Corrected', app(TimeTrackingService::class)->updateTimeEntry((int) $entry->id, ['title' => 'Corrected'])->title);
        $successor->update(['status' => 'approved']);
        $this->denied(fn () => app(TimeTrackingService::class)->submitTimeEntry((int) $entry->id));
    }

    public function test_old_scope_cannot_be_evaded_by_moving_fact_to_an_open_project_and_month(): void
    {
        [$context, $project] = $this->context();
        $other = Project::factory()->create(['organization_id' => $context->organization->id]);
        $entry = TimeEntry::create($this->fact($context, $project) + ['status' => 'draft']);
        $this->period($context, $project);
        $this->denied(fn () => app(TimeTrackingService::class)->updateTimeEntry((int) $entry->id, [
            'project_id' => $other->id, 'work_date' => '2026-07-12', 'start_time' => '08:00', 'end_time' => '09:00',
        ]));
        self::assertSame((int) $project->id, (int) $entry->refresh()->project_id);
        self::assertSame('2026-05-12', $entry->work_date->format('Y-m-d'));
    }

    public function test_contract_timezone_determines_month_and_unknown_timezone_protects_boundary(): void
    {
        [$context, $project] = $this->context();
        $this->period($context, $project);
        $guard = app(WorkforcePeriodMutationGuard::class);
        // Moscow June 1: closing May must not reject a measured June fact, even with a UTC source date.
        $guard->assertMutable((int) $context->organization->id, (int) $project->id, [
            'user_id' => $context->user->id, 'work_date' => '2026-05-31', 'timezone' => 'UTC',
            'start_at' => '2026-05-31T22:00:00Z', 'end_at' => '2026-05-31T23:00:00Z',
        ]);
        $created = app(TimeTrackingService::class)->createTimeEntry(array_replace($this->fact($context, $project), [
            'work_date' => '2026-05-31', 'timezone' => 'UTC', 'start_at' => '2026-05-31T22:00:00Z', 'end_at' => '2026-05-31T23:00:00Z',
        ]));
        self::assertSame('draft', $created->status);
        $this->denied(fn () => app(TimeTrackingService::class)->createTimeEntry(array_replace($this->fact($context, $project), [
            'work_date' => '2026-05-31', 'timezone' => 'UTC', 'start_at' => '2026-05-31T19:00:00Z', 'end_at' => '2026-05-31T20:00:00Z',
        ])));
        $this->denied(fn () => $guard->assertMutable((int) $context->organization->id, (int) $project->id, [
            'user_id' => $context->user->id, 'work_date' => '2026-06-01', 'timezone' => 'Pacific/Auckland',
            'start_at' => '2026-05-31T19:00:00Z', 'end_at' => '2026-05-31T20:00:00Z',
        ]));
        $this->denied(fn () => $guard->assertMutable((int) $context->organization->id, (int) $project->id, [
            'work_date' => '2026-06-01', 'timezone' => 'UTC', 'start_at' => '2026-05-31T22:00:00Z', 'end_at' => '2026-05-31T23:00:00Z',
        ]));
    }

    public function test_import_preview_reports_closed_rows_and_commit_rechecks_after_preview(): void
    {
        [$context, $project] = $this->context();
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['project', 'user', 'date', 'hours', 'title'], [$project->id, $context->user->id, '2026-05-12', 1, 'Import']]);
        $path = tempnam(sys_get_temp_dir(), 'hr-closed-import-');
        (new Xlsx($book))->save($path);
        try {
            $file = new UploadedFile($path, 'hours.xlsx', null, null, true);
            $mapping = ['project_id' => 'project', 'user_id' => 'user', 'work_date' => 'date', 'hours_worked' => 'hours', 'title' => 'title'];
            $imports = app(TimeEntryImportService::class);
            $preview = $imports->preview((int) $context->organization->id, $context->user, $file, $mapping);
            self::assertTrue($preview['can_commit']);
            $this->period($context, null);
            $this->denied(fn () => $imports->commit((int) $context->organization->id, $context->user, $preview['preview_token']));
            $closedPreview = $imports->preview((int) $context->organization->id, $context->user, $file, $mapping);
            self::assertFalse($closedPreview['can_commit']);
            self::assertSame([trans_message('workforce.errors.timekeeping_closed')], $closedPreview['rows'][0]['errors']['row']);
            self::assertDatabaseCount('time_entries', 0);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_closed_month_rejects_production_output_and_acceptance(): void
    {
        [$context, $project] = $this->context();
        $order = ProductionLaborWorkOrder::create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'created_by_user_id' => $context->user->id,
            'order_number' => 'CLOSED-1', 'title' => 'Closed source', 'assignee_type' => 'brigade', 'status' => 'issued',
        ]);
        $line = $order->lines()->create(['organization_id' => $context->organization->id, 'name' => 'Work', 'unit' => 'unit', 'planned_quantity' => 10, 'unit_rate' => 1, 'planned_hours' => 8, 'hour_rate' => 1, 'pay_basis' => 'volume', 'requires_safety_permit' => false]);
        $service = app(ProductionLaborService::class);
        $output = $service->recordOutput((int) $context->organization->id, (int) $context->user->id, ['work_order_line_id' => $line->id, 'work_date' => '2026-05-12', 'quantity' => 1, 'hours' => 1]);
        $order->update(['status' => 'submitted']);
        $this->period($context, $project);
        $this->denied(fn () => $service->acceptWorkOrder($order, (int) $context->user->id));
        self::assertSame('submitted', $order->refresh()->status);
        $order->update(['status' => 'issued']);
        $this->denied(fn () => $service->recordOutput((int) $context->organization->id, (int) $context->user->id, ['work_order_line_id' => $line->id, 'work_date' => '2026-05-12', 'quantity' => 1, 'hours' => 1]));
        self::assertDatabaseCount('production_labor_output_entries', 1);
        self::assertSame('accepted', $output->refresh()->status);
    }

    private function context(): array
    {
        $context = AdminApiTestContext::create();
        $this->actingAs($context->user);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value]);
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($context): void {
            $mock->shouldReceive('can')->andReturnUsing(static function (User $actor, string $permission, array $scope = []) use ($context): bool {
                return (int) $actor->id === (int) $context->user->id
                    && (int) ($scope['organization_id'] ?? 0) === (int) $context->organization->id
                    && in_array($permission, ['time_tracking.view', 'time_tracking.create', 'time_tracking.edit', 'time_tracking.submit', 'time_tracking.approve', 'time_tracking.reject', 'production-labor.output.record'], true);
            });
        });
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = WorkforceEmployee::create(['organization_id' => $context->organization->id, 'user_id' => $context->user->id, 'personnel_number' => 'CLOSED-EMP', 'last_name' => 'Worker', 'first_name' => 'Test', 'employment_status' => 'active', 'hire_date' => '2025-01-01', 'hr_reconciliation_status' => 'reconciled']);
        EmploymentContract::create(['organization_id' => $context->organization->id, 'employee_id' => $employee->id, 'contract_number' => 'CLOSED-CONTRACT', 'contract_date' => '2025-01-01', 'start_date' => '2025-01-01', 'status' => 'active', 'relation_type' => 'employment', 'employment_mode' => 'main', 'job_share' => 1, 'weekly_norm_hours' => 40, 'timezone' => 'Europe/Moscow', 'summed_accounting_period' => 'month', 'calendar_profile' => 'ru_standard']);

        return [$context, $project];
    }

    private function fact(AdminApiTestContext $context, Project $project): array
    {
        return ['organization_id' => $context->organization->id, 'user_id' => $context->user->id, 'worker_type' => 'user', 'project_id' => $project->id, 'work_date' => '2026-05-12', 'start_time' => '08:00', 'end_time' => '09:00', 'start_at' => '2026-05-12T05:00:00Z', 'end_at' => '2026-05-12T06:00:00Z', 'timezone' => 'Europe/Moscow', 'net_microseconds' => 3_600_000_000, 'net_minutes' => 60, 'hours_worked' => 1, 'title' => 'Measured fact', 'is_billable' => true];
    }

    private function period(AdminApiTestContext $context, ?Project $project, string $status = 'closed', ?int $supersedes = null, string $month = '2026-05-01'): TimekeepingPeriod
    {
        return TimekeepingPeriod::create(['organization_id' => $context->organization->id, 'project_id' => $project?->id, 'month' => $month, 'status' => $status, 'revision' => 1, 'supersedes_id' => $supersedes, 'rule_snapshot' => [], 'snapshot' => [], 'snapshot_sha256' => hash('sha256', '[]'), 'created_by' => $context->user->id]);
    }

    private function denied(callable $mutation, string $errorKey = 'workforce.errors.timekeeping_closed'): void
    {
        try {
            $mutation();
            self::fail('A frozen period accepted a source mutation.');
        } catch (DomainException $exception) {
            self::assertSame(trans_message($errorKey), $exception->getMessage());
        }
    }
}
