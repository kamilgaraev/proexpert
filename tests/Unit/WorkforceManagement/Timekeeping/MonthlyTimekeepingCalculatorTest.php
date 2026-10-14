<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Timekeeping;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\MonthlyTimekeepingCalculator;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingRules;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingState;
use PHPUnit\Framework\TestCase;

final class MonthlyTimekeepingCalculatorTest extends TestCase
{
    public function test_plan_never_becomes_actual_and_legacy_relation_is_not_invented(): void
    {
        $employee = $this->employee();
        $employee['plans'] = [['work_date' => '2026-09-02', 'minutes' => 480, 'source' => ['type' => 'schedule', 'id' => 7, 'revision' => 1]]];
        $result = $this->calculate([$employee]);
        self::assertEquals(480, $result['summary']['planned_minutes']);
        self::assertEquals(0, $result['summary']['fact_minutes']);
        self::assertContains('measured_fact_missing', array_column($result['issues'], 'code'));
        $employee['relations'][0]['relation_type'] = null;
        $result = $this->calculate([$employee]);
        self::assertContains('relation_reconciliation_required', array_column($result['issues'], 'code'));
        self::assertNull($result['rows'][0]['relation_type']);
    }

    public function test_night_crosses_month_and_explicit_break_without_truncating_to_work_date(): void
    {
        $employee = $this->employee();
        $employee['facts'] = [$this->fact('2026-08-31', '2026-08-31T23:00:00+03:00', '2026-09-01T02:00:00+03:00', ['break_intervals' => [['start_at' => '2026-09-01T00:30:00+03:00', 'end_at' => '2026-09-01T01:00:00+03:00']]])];
        $result = $this->calculate([$employee]);
        self::assertEquals(90, $result['summary']['fact_minutes']);
        self::assertEquals(90, $result['summary']['night_minutes']);
        self::assertSame('2026-09-01', $result['rows'][0]['days'][0]['work_date']);
    }

    public function test_unknown_break_placement_preserves_reported_hours_as_unverified(): void
    {
        $employee = $this->employee();
        $employee['facts'] = [$this->fact('2026-09-02', '2026-09-02T09:00:00+03:00', '2026-09-02T18:00:00+03:00', ['break_time' => 1, 'hours_worked' => 8])];
        $result = $this->calculate([$employee]);
        self::assertEquals(0, $result['summary']['fact_minutes']);
        self::assertEquals(480, $result['summary']['reported_unverified_minutes']);
        self::assertContains('interval_reconciliation_required', array_column($result['issues'], 'code'));
    }

    public function test_multiple_contracts_require_allocation_and_keep_unallocated_actual_once(): void
    {
        $employee = $this->employee();
        $employee['relations'][] = array_merge($employee['relations'][0], ['id' => 12, 'contract_number' => 'C-12', 'employment_mode' => 'internal_secondary', 'job_share' => 0.5, 'weekly_norm_hours' => 20]);
        $employee['facts'] = [$this->fact('2026-09-02', '2026-09-02T09:00:00+03:00', '2026-09-02T17:00:00+03:00')];
        $result = $this->calculate([$employee]);
        self::assertCount(3, $result['rows']);
        self::assertNull($result['rows'][2]['employment_contract_id']);
        self::assertEquals(480, $result['rows'][2]['summary']['fact_minutes']);
        self::assertEquals(480, $result['summary']['fact_minutes']);
        self::assertEquals(0, $result['rows'][0]['summary']['fact_minutes']);
        self::assertContains('fact_without_employment_relation', array_column($result['issues'], 'code'));
        $employee['facts'][0]['employment_contract_id'] = 12;
        $result = $this->calculate([$employee]);
        self::assertCount(2, $result['rows']);
        self::assertEquals(480, $result['rows'][1]['summary']['fact_minutes']);
        self::assertEquals(0, $result['rows'][0]['summary']['fact_minutes']);
    }

    public function test_overlap_is_not_double_counted_and_conflicting_record_stays_visible(): void
    {
        $employee = $this->employee();
        $employee['facts'] = [$this->fact('2026-09-02', '2026-09-02T09:00:00+03:00', '2026-09-02T12:00:00+03:00'), $this->fact('2026-09-02', '2026-09-02T11:00:00+03:00', '2026-09-02T14:00:00+03:00', ['source_id' => 2])];
        $result = $this->calculate([$employee]);
        self::assertEquals(180, $result['summary']['fact_minutes']);
        self::assertEquals(180, $result['summary']['reported_unverified_minutes']);
        self::assertNotEmpty($result['rows'][0]['days'][1]['unresolved_facts']);
        self::assertContains('measured_interval_overlap', array_column($result['issues'], 'code'));
    }

    public function test_two_manual_intervals_in_one_correction_both_count(): void
    {
        $employee = $this->employee();
        $employee['cells'] = [['id' => 3, 'revision' => 2, 'employment_contract_id' => 11, 'work_date' => '2026-09-02', 'reason_code' => 'manual_correction', 'basis_reference' => 'Приказ1', 'intervals' => [$this->fact('2026-09-02', '2026-09-02T09:00:00+03:00', '2026-09-02T12:00:00+03:00'), $this->fact('2026-09-02', '2026-09-02T13:00:00+03:00', '2026-09-02T18:00:00+03:00')]]];
        $result = $this->calculate([$employee]);
        self::assertEquals(480, $result['summary']['fact_minutes']);
    }

    public function test_quarter_accounting_does_not_label_daily_excess_as_overtime_mid_period(): void
    {
        $employee = $this->employee();
        $employee['relations'][0]['summed_accounting_period'] = 'quarter';
        $employee['facts'] = [$this->fact('2026-08-05', '2026-08-05T09:00:00+03:00', '2026-08-05T21:00:00+03:00')];
        $result = $this->calculate([$employee], '2026-08');
        self::assertEquals(720, $result['summary']['fact_minutes']);
        self::assertEquals(0, $result['summary']['overtime_minutes']);
        self::assertContains('overtime_pending_accounting_period', array_column($result['issues'], 'code'));
    }

    public function test_part_time_norm_and_preholiday_are_calendar_versioned(): void
    {
        $relation = $this->employee()['relations'][0];
        $rules = TimekeepingRules::snapshot();
        $relation['weekly_norm_hours'] = 18;
        $relation['job_share'] = 0.5;
        self::assertEquals(216, TimekeepingRules::normMinutes('2026-04-29', $relation, $rules));
        self::assertEquals(156, TimekeepingRules::normMinutes('2026-04-30', $relation, $rules));
        self::assertEquals(0, TimekeepingRules::normMinutes('2026-01-09', $relation, $rules));
        self::assertNull(TimekeepingRules::normMinutes('2027-01-11', $relation, $rules));
    }

    public function test_closed_state_rejects_changes_and_optimistic_revision_mismatch(): void
    {
        $this->expectExceptionMessage('timekeeping_not_draft');
        TimekeepingState::editable('closed', 4, 4);
    }

    public function test_revision_conflict_does_not_unlock_even_draft(): void
    {
        $this->expectExceptionMessage('timekeeping_revision_conflict');
        TimekeepingState::editable('draft', 4, 3);
    }

    public function test_approval_requires_verified_state(): void
    {
        $this->expectExceptionMessage('timekeeping_transition_invalid');
        TimekeepingState::next('draft', 'approve');
    }

    public function test_measured_seconds_crossing_night_and_month_keep_exact_microseconds(): void
    {
        $employee = $this->employee();
        $employee['facts'] = [$this->fact('2026-08-31', '2026-08-31T23:59:59.500000+03:00', '2026-09-01T00:00:30.750000+03:00')];
        $result = $this->calculate([$employee]);
        self::assertSame(30750000, $result['summary']['fact_microseconds']);
        self::assertSame(30750000, $result['summary']['night_microseconds']);
        self::assertEqualsWithDelta(0.5125, $result['summary']['fact_minutes'], 0.0000000001);
        self::assertNotContains('interval_reconciliation_required', array_column($result['issues'], 'code'));
    }

    public function test_precise_breaks_near_22_are_subtracted_from_the_correct_night_segment(): void
    {
        $employee = $this->employee();
        $employee['facts'] = [$this->fact('2026-09-02', '2026-09-02T21:59:40+03:00', '2026-09-02T22:00:40+03:00', ['break_intervals' => [['start_at' => '2026-09-02T21:59:50+03:00', 'end_at' => '2026-09-02T22:00:05+03:00']]])];
        $result = $this->calculate([$employee]);
        self::assertSame(45000000, $result['summary']['fact_microseconds']);
        self::assertSame(35000000, $result['summary']['night_microseconds']);
    }

    public function test_cross_contract_overlaps_are_visible_but_never_counted_twice_as_approved_fact(): void
    {
        $employee = $this->employee();
        $employee['relations'][] = array_merge($employee['relations'][0], ['id' => 12, 'contract_number' => 'C-12', 'employment_mode' => 'internal_secondary', 'job_share' => 0.5, 'weekly_norm_hours' => 20]);
        $employee['facts'] = [$this->fact('2026-09-02', '2026-09-02T09:00:00+03:00', '2026-09-02T12:00:00+03:00', ['employment_contract_id' => 11]), $this->fact('2026-09-02', '2026-09-02T10:00:00+03:00', '2026-09-02T13:00:00+03:00', ['source_id' => 2, 'employment_contract_id' => 12])];
        $result = $this->calculate([$employee]);
        self::assertEquals(180, $result['summary']['fact_minutes']);
        self::assertEquals(180, $result['summary']['reported_unverified_minutes']);
        self::assertContains('cross_contract_interval_overlap', array_column($result['issues'], 'code'));
    }

    private function calculate(array $employees, string $month = '2026-09'): array
    {
        return (new MonthlyTimekeepingCalculator)->calculate($month, $employees, TimekeepingRules::snapshot());
    }

    private function fact(string $date, string $start, string $end, array $extra = []): array
    {
        return array_merge(['source_type' => 'time_entry', 'source_id' => 1, 'work_date' => $date, 'start_at' => $start, 'end_at' => $end, 'timezone' => 'Europe/Moscow'], $extra);
    }

    private function employee(): array
    {
        return ['id' => 1, 'personnel_number' => 'E1', 'full_name' => 'Иванов Иван', 'relations' => [['id' => 11, 'contract_number' => 'C-11', 'status' => 'active', 'start_date' => '2026-01-01', 'end_date' => null, 'relation_type' => 'employment', 'employment_mode' => 'main', 'timezone' => 'Europe/Moscow', 'weekly_norm_hours' => 40, 'job_share' => 1, 'calendar_profile' => 'ru-five-day', 'summed_accounting_period' => null, 'revision' => 1, 'metadata' => []]], 'facts' => [], 'cells' => [], 'plans' => [], 'absences' => []];
    }
}
