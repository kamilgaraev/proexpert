<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrEvent;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\EmploymentRelationService;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\MonthlyTimekeepingCalculator;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingRules;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingService;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingSnapshotHash;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingSourceReader;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceEmployeeService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceHrFrozenSourceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('oldRanges')]
    public function test_shorter_new_relation_cannot_remove_old_frozen_month(string $status, ?string $oldEnd, bool $legacy): void
    {
        [$context, $employee] = $this->fixture();
        $old = $this->contract($context, $employee, $oldEnd, $legacy);
        $before = $this->month($context);
        $period = $this->period($context, $status, $before);
        $input = array_replace($this->terms('NEW', $legacy ? '2026-01-01' : '2026-02-01', '2026-02-28'), ['event_type' => $legacy ? 'reconcile' : 'change', 'supersedes_id' => $old->id]);

        $this->denied(fn () => app(EmploymentRelationService::class)->record($context->organization->id, $employee->id, $input, $context->user));
        self::assertSame($oldEnd, $old->refresh()->end_date?->toDateString());
        self::assertSame('active', $old->status);
        self::assertSame($legacy ? null : 'employment', $old->relation_type);
        self::assertSame(1, EmploymentContract::query()->count());
        self::assertSame(0, HrEvent::query()->count());
        self::assertEquals($before, $this->month($context));
        self::assertSame(TimekeepingSnapshotHash::calculate($before), $period->refresh()->snapshot_sha256);
    }

    public static function oldRanges(): array
    {
        return ['open old tail' => ['closed', null, false], 'longer bounded old tail' => ['approved', '2026-03-31', false], 'same-start legacy superseding' => ['closed', null, true]];
    }

    public function test_future_change_preserves_earlier_frozen_month_and_records_new_version(): void
    {
        [$context, $employee] = $this->fixture();
        $old = $this->contract($context, $employee);
        $before = $this->month($context);
        $period = $this->period($context, 'closed', $before);
        $new = app(EmploymentRelationService::class)->record($context->organization->id, $employee->id,
            array_replace($this->terms('FUTURE', '2026-04-01', '2026-05-31'), ['event_type' => 'change', 'supersedes_id' => $old->id]), $context->user);

        self::assertSame('2026-03-31', $old->refresh()->end_date->toDateString());
        self::assertSame((int) $old->id, (int) $new->supersedes_id);
        self::assertSame(2, (int) $new->revision);
        self::assertEquals($before, $this->month($context));
        self::assertSame(TimekeepingSnapshotHash::calculate($before), $period->refresh()->snapshot_sha256);
        self::assertNull(HrEvent::query()->firstOrFail()->before_state['end_date']);
    }

    public function test_new_relation_cannot_add_missing_employee_to_frozen_organization_month(): void
    {
        [$context, $employee] = $this->fixture();
        $period = $this->period($context, 'closed', ['rows' => []]);
        $this->denied(fn () => app(EmploymentRelationService::class)->record($context->organization->id, $employee->id, $this->terms('NEW', '2026-03-01', '2026-03-31'), $context->user));
        self::assertSame(0, EmploymentContract::query()->count());
        self::assertSame(0, HrEvent::query()->count());
        self::assertSame(['rows' => []], $period->refresh()->snapshot);
    }

    public function test_unrelated_project_month_does_not_block_new_terms(): void
    {
        [$context, $employee] = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $period = $this->period($context, 'closed', ['rows' => []], (int) $project->id);
        $new = app(EmploymentRelationService::class)->record($context->organization->id, $employee->id, $this->terms('NEW', '2026-03-01', '2026-03-31'), $context->user);
        self::assertNotNull($new->id);
        self::assertSame([], app(TimekeepingSourceReader::class)->read($context->organization->id, '2026-03', (int) $project->id, 0));
        self::assertSame(['rows' => []], $period->refresh()->snapshot);
    }

    public function test_known_new_civil_engagement_is_outside_frozen_timekeeping_policy(): void
    {
        [$context, $employee] = $this->fixture();
        $period = $this->period($context, 'closed', ['rows' => []]);
        $civil = app(EmploymentRelationService::class)->record($context->organization->id, $employee->id, $this->civilTerms(), $context->user);
        self::assertSame('civil', $civil->relation_type);
        self::assertSame(['rows' => []], $period->refresh()->snapshot);
        self::assertSame(1, HrEvent::query()->count());
    }

    public function test_known_civil_engagement_still_obeys_locked_payroll_period(): void
    {
        [$context, $employee] = $this->fixture();
        DB::table('workforce_payroll_periods')->insert(['organization_id' => $context->organization->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'status' => 'locked']);
        $this->denied(fn () => app(EmploymentRelationService::class)->record($context->organization->id, $employee->id, $this->civilTerms(), $context->user), 'payroll_period_locked');
        self::assertSame(0, EmploymentContract::query()->count());
        self::assertSame(0, HrEvent::query()->count());
    }

    public function test_old_employment_tail_removal_is_blocked_by_payroll_after_new_end(): void
    {
        [$context, $employee] = $this->fixture();
        $old = $this->contract($context, $employee);
        DB::table('workforce_payroll_periods')->insert(['organization_id' => $context->organization->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'status' => 'locked']);
        $input = array_replace($this->terms('SHORT', '2026-02-01', '2026-02-28'), ['event_type' => 'change', 'supersedes_id' => $old->id]);
        $this->denied(fn () => app(EmploymentRelationService::class)->record($context->organization->id, $employee->id, $input, $context->user), 'payroll_period_locked');
        self::assertNull($old->refresh()->end_date);
        self::assertSame(1, EmploymentContract::query()->count());
        self::assertSame(0, HrEvent::query()->count());
    }

    public function test_finite_old_new_ranges_do_not_block_unrelated_later_locked_month(): void
    {
        [$context, $employee] = $this->fixture();
        $old = $this->contract($context, $employee, '2026-02-28');
        DB::table('workforce_payroll_periods')->insert(['organization_id' => $context->organization->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'status' => 'locked']);
        $input = array_replace($this->terms('SHORT', '2026-02-01', '2026-02-28'), ['event_type' => 'change', 'supersedes_id' => $old->id]);
        $new = app(EmploymentRelationService::class)->record($context->organization->id, $employee->id, $input, $context->user);
        self::assertSame('2026-01-31', $old->refresh()->end_date->toDateString());
        self::assertSame('2026-02-28', $new->end_date->toDateString());
        self::assertSame((int) $old->id, (int) $new->supersedes_id);
    }

    public function test_assigned_project_month_blocks_new_terms_even_when_employee_was_missing(): void
    {
        [$context, $employee] = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $org = $context->organization->id;
        $department = DB::table('workforce_departments')->insertGetId(['organization_id' => $org, 'code' => 'PROJECT', 'name' => 'Участок']);
        $position = DB::table('workforce_positions')->insertGetId(['organization_id' => $org, 'code' => 'ROLE', 'name' => 'Монтажник']);
        $unit = DB::table('workforce_staff_units')->insertGetId(['organization_id' => $org, 'department_id' => $department, 'position_id' => $position, 'code' => 'UNIT', 'valid_from' => '2026-01-01']);
        DB::table('workforce_employee_assignments')->insert(['organization_id' => $org, 'employee_id' => $employee->id, 'staff_unit_id' => $unit, 'department_id' => $department, 'position_id' => $position, 'project_id' => $project->id, 'valid_from' => '2026-01-01', 'status' => 'active']);
        $period = $this->period($context, 'closed', ['rows' => []], (int) $project->id);

        $this->denied(fn () => app(EmploymentRelationService::class)->record($org, $employee->id, $this->terms('NEW', '2026-03-01', '2026-03-31'), $context->user));
        self::assertSame(0, EmploymentContract::query()->count());
        self::assertSame(['rows' => []], $period->refresh()->snapshot);
    }

    #[DataProvider('frozenStatuses')]
    public function test_user_relink_cannot_switch_frozen_month_facts(string $status): void
    {
        [$context, $employee] = $this->fixture();
        $newUser = $this->bindingFacts($context, $employee);
        $before = $this->month($context);
        self::assertEquals(120, $before['summary']['fact_minutes']);
        $period = $this->period($context, $status, $before);

        $this->denied(fn () => app(WorkforceEmployeeService::class)->update($context->organization->id, $employee->id, ['user_id' => $newUser->id, 'basis_reference' => 'Изменение учётной связи'], $context->user));
        self::assertSame((int) $context->user->id, (int) $employee->refresh()->user_id);
        self::assertEquals($before, $this->month($context));
        self::assertSame(TimekeepingSnapshotHash::calculate($before), $period->refresh()->snapshot_sha256);
        self::assertSame(0, HrEvent::query()->count());
    }

    public static function frozenStatuses(): array
    {
        return [['approved'], ['closed']];
    }

    public function test_same_user_and_unrelated_card_fields_remain_editable_with_frozen_month(): void
    {
        [$context, $employee] = $this->fixture();
        $this->bindingFacts($context, $employee);
        $this->period($context, 'closed', $this->month($context));
        $result = app(WorkforceEmployeeService::class)->update($context->organization->id, $employee->id, ['user_id' => $context->user->id, 'middle_name' => 'Иванович', 'basis_reference' => 'Уточнение карточки'], $context->user);
        self::assertSame('Иванович', $result->middle_name);
        self::assertSame((int) $context->user->id, (int) $result->user_id);
    }

    public function test_draft_successor_allows_rebinding_and_refresh_preserves_closed_ancestor(): void
    {
        [$context, $employee] = $this->fixture();
        $newUser = $this->bindingFacts($context, $employee);
        $before = $this->month($context);
        $period = $this->period($context, 'closed', $before);
        $timekeeping = app(TimekeepingService::class);
        $successor = $timekeeping->action($context->organization->id, $context->user, $period->id, ['action' => 'reopen', 'expected_revision' => 1, 'basis_reference' => 'Исправление связи источника']);
        app(WorkforceEmployeeService::class)->update($context->organization->id, $employee->id, ['user_id' => $newUser->id, 'basis_reference' => 'Исправление связи источника'], $context->user);
        $refreshed = $timekeeping->refresh($context->organization->id, $context->user, $successor['id'], 1);

        self::assertSame($period->id, $refreshed['supersedes_id']);
        self::assertEquals(240, $refreshed['summary']['fact_minutes']);
        self::assertSame('draft', $refreshed['status']);
        self::assertSame('closed', $period->refresh()->status);
        self::assertEquals($before, $period->snapshot);
        self::assertSame(TimekeepingSnapshotHash::calculate($before), $period->snapshot_sha256);
        self::assertSame((int) $context->user->id, (int) HrEvent::query()->firstOrFail()->before_state['user_id']);
        self::assertSame((int) $newUser->id, (int) HrEvent::query()->firstOrFail()->after_state['user_id']);
    }

    public function test_locked_payroll_source_blocks_user_relink_without_monthly_freeze(): void
    {
        [$context, $employee] = $this->fixture();
        $newUser = $this->bindingFacts($context, $employee);
        $projectId = DB::table('projects')->where('organization_id', $context->organization->id)->value('id');
        $periodId = DB::table('workforce_payroll_periods')->insertGetId(['organization_id' => $context->organization->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'status' => 'draft']);
        $sourceId = DB::table('workforce_payroll_source_rows')->insertGetId(['organization_id' => $context->organization->id, 'payroll_period_id' => $periodId, 'employee_id' => $employee->id, 'project_id' => $projectId, 'work_date' => '2026-03-02', 'source_type' => 'time_entry', 'hours' => 2]);
        DB::table('workforce_payroll_periods')->where('id', $periodId)->update(['status' => 'locked']);
        $before = $this->month($context);

        $this->denied(fn () => app(WorkforceEmployeeService::class)->update($context->organization->id, $employee->id, ['user_id' => $newUser->id, 'basis_reference' => 'Изменение связи'], $context->user), 'payroll_period_locked');
        self::assertSame((int) $context->user->id, (int) $employee->refresh()->user_id);
        self::assertSame('locked', DB::table('workforce_payroll_periods')->where('id', $periodId)->value('status'));
        self::assertEquals(2, DB::table('workforce_payroll_source_rows')->where('id', $sourceId)->value('hours'));
        self::assertEquals($before, $this->month($context));
        self::assertSame(0, HrEvent::query()->count());
    }

    public function test_unfrozen_user_relink_remains_available_and_records_history(): void
    {
        [$context, $employee] = $this->fixture();
        $newUser = $this->bindingFacts($context, $employee);
        app(WorkforceEmployeeService::class)->update($context->organization->id, $employee->id, ['user_id' => $newUser->id, 'basis_reference' => 'Исправление учётной связи'], $context->user);
        self::assertSame((int) $newUser->id, (int) $employee->refresh()->user_id);
        self::assertEquals(240, $this->month($context)['summary']['fact_minutes']);
        self::assertSame(1, HrEvent::query()->count());
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create();
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canCurrent')->andReturnTrue();
        });
        $employee = WorkforceEmployee::create(['organization_id' => $context->organization->id, 'personnel_number' => 'FROZEN-HR', 'first_name' => 'Иван', 'last_name' => 'Петров', 'hire_date' => '2026-01-01', 'employment_status' => 'active']);

        return [$context, $employee];
    }

    private function terms(string $number, string $start = '2026-01-01', ?string $end = null): array
    {
        return ['relation_type' => 'employment', 'employment_mode' => 'main', 'contract_number' => $number, 'contract_date' => $start, 'start_date' => $start, 'end_date' => $end, 'job_share' => 1, 'weekly_norm_hours' => 40, 'timezone' => 'Europe/Moscow', 'calendar_profile' => 'ru-five-day', 'event_type' => 'hire', 'basis_reference' => 'Бумажный договор '.$number];
    }

    private function contract(AdminApiTestContext $context, WorkforceEmployee $employee, ?string $end = null, bool $legacy = false): EmploymentContract
    {
        $terms = array_diff_key($this->terms('OLD', '2026-01-01', $end), array_flip(['event_type', 'basis_reference']));

        return EmploymentContract::create(array_replace($terms, ['organization_id' => $context->organization->id, 'employee_id' => $employee->id, 'status' => 'active', 'revision' => 1, 'relation_type' => $legacy ? null : 'employment']));
    }

    private function civilTerms(): array
    {
        return ['relation_type' => 'civil', 'contract_number' => 'CIVIL', 'contract_date' => '2026-03-01', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'event_type' => 'hire', 'basis_reference' => 'Бумажный договор ГПХ'];
    }

    private function period(AdminApiTestContext $context, string $status, array $snapshot, ?int $projectId = null): TimekeepingPeriod
    {
        return TimekeepingPeriod::create(['organization_id' => $context->organization->id, 'project_id' => $projectId, 'month' => '2026-03-01', 'status' => $status, 'revision' => 1, 'snapshot' => $snapshot, 'snapshot_sha256' => TimekeepingSnapshotHash::calculate($snapshot), 'rule_snapshot' => TimekeepingRules::snapshot(), 'created_by' => $context->user->id]);
    }

    private function month(AdminApiTestContext $context): array
    {
        return app(MonthlyTimekeepingCalculator::class)->calculate('2026-03', app(TimekeepingSourceReader::class)->read($context->organization->id, '2026-03', null, 0), TimekeepingRules::snapshot());
    }

    private function bindingFacts(AdminApiTestContext $context, WorkforceEmployee $employee): User
    {
        $employee->update(['user_id' => $context->user->id]);
        $this->contract($context, $employee);
        $newUser = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $newUser->organizations()->attach($context->organization->id, ['is_active' => true]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        foreach ([[$context->user->id, 2], [$newUser->id, 4]] as [$userId, $hours]) {
            DB::table('time_entries')->insert(['organization_id' => $context->organization->id, 'user_id' => $userId, 'project_id' => $project->id, 'work_date' => '2026-03-02', 'title' => 'Подтверждённый интервал', 'status' => 'approved', 'start_time' => '09:00', 'end_time' => sprintf('%02d:00', 9 + $hours), 'start_at' => '2026-03-02 06:00:00+00:00', 'end_at' => sprintf('2026-03-02 %02d:00:00+00:00', 6 + $hours), 'timezone' => 'Europe/Moscow', 'timezone_requires_reconciliation' => false, 'net_minutes' => $hours * 60, 'net_microseconds' => $hours * 3600000000, 'hours_worked' => $hours, 'break_time' => 0, 'custom_fields' => json_encode(['time_tracking_interval' => ['clock_source' => 'absolute']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $newUser;
    }

    private function denied(callable $mutation, string $key = 'hr_history_frozen'): void
    {
        try {
            $mutation();
            self::fail('frozen source mutation accepted');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.'.$key), $exception->getMessage());
        }
    }
}
