<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Timekeeping;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\MonthlyTimekeepingCalculator;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingRules;
use PHPUnit\Framework\TestCase;

final class TimekeepingSourceSemanticsTest extends TestCase
{
    public function test_absolute_instant_uses_contract_date_weekend_and_effective_start(): void
    {
        $employee = $this->employee();
        $employee['relations'][0]['start_date'] = '2026-09-05';
        $employee['facts'] = [$this->absolute()];
        $result = $this->calculate($employee);
        $day = $result['rows'][0]['days'][4];

        self::assertCount(1, $result['rows']);
        self::assertSame(11, $result['rows'][0]['employment_contract_id']);
        self::assertEquals(120, $day['fact_minutes']);
        self::assertEquals(120, $day['night_minutes']);
        self::assertEquals(120, $day['weekend_minutes']);
        self::assertSame('2026-09-04', $day['intervals'][0]['source_work_date']);
        self::assertNotContains('interval_reconciliation_required', array_column($result['issues'], 'code'));
        self::assertNotContains('fact_timezone_mismatch', array_column($result['issues'], 'code'));
    }

    public function test_provisional_legacy_clocks_are_reparsed_in_confirmed_zone(): void
    {
        $employee = $this->employee();
        $employee['relations'][0]['timezone'] = 'Asia/Vladivostok';
        $employee['facts'] = [array_replace($this->absolute(), ['work_date' => '2026-09-05', 'clock_source' => 'legacy_local', 'start_time' => '23:00:00', 'end_time' => '01:00:00', 'start_at' => '2026-09-05T23:00:00Z', 'end_at' => '2026-09-06T01:00:00Z'])];
        $result = $this->calculate($employee);

        self::assertEquals(120, $result['summary']['fact_minutes']);
        self::assertEquals(120, $result['summary']['night_minutes']);
        self::assertSame('13:00', (new \DateTimeImmutable($result['rows'][0]['days'][4]['intervals'][0]['start_at']))->format('H:i'));
        self::assertEquals(60, $result['rows'][0]['days'][4]['fact_minutes']);
        self::assertEquals(60, $result['rows'][0]['days'][5]['fact_minutes']);
    }

    public function test_unknown_clock_provenance_remains_unresolved_despite_iso_values(): void
    {
        $employee = $this->employee();
        $employee['facts'] = [array_replace($this->absolute(), ['clock_source' => 'unknown', 'hours_worked' => 2])];
        $result = $this->calculate($employee);

        self::assertEquals(0, $result['summary']['fact_minutes']);
        self::assertEquals(120, $result['summary']['reported_unverified_minutes']);
        self::assertContains('interval_reconciliation_required', array_column($result['issues'], 'code'));
    }

    public function test_resolving_timezone_does_not_resolve_unknown_break_placement(): void
    {
        $employee = $this->employee();
        $employee['facts'] = [array_replace($this->absolute(), ['break_time' => 0.5, 'hours_worked' => 1.5])];
        $result = $this->calculate($employee);

        self::assertEquals(0, $result['summary']['fact_minutes']);
        self::assertEquals(90, $result['summary']['reported_unverified_minutes']);
        self::assertContains('interval_reconciliation_required', array_column($result['issues'], 'code'));
    }

    public function test_overlap_detection_survives_source_date_reclassification(): void
    {
        $employee = $this->employee();
        $employee['relations'][] = array_replace($employee['relations'][0], ['id' => 12, 'contract_number' => 'SECONDARY', 'employment_mode' => 'internal_secondary']);
        $employee['facts'] = [array_replace($this->absolute(), ['employment_contract_id' => 11]), array_replace($this->absolute(), ['source_id' => 2, 'employment_contract_id' => 12])];
        $result = $this->calculate($employee);

        self::assertEquals(120, $result['summary']['fact_minutes']);
        self::assertEquals(120, $result['summary']['reported_unverified_minutes']);
        self::assertContains('cross_contract_interval_overlap', array_column($result['issues'], 'code'));
    }

    public function test_approved_business_trip_preserves_grounds_and_does_not_create_hours(): void
    {
        $employee = $this->employee();
        $employee['absences'] = [$this->trip()];
        $result = $this->calculate($employee);
        $day = $result['rows'][0]['days'][1];

        self::assertSame('business_trip', $day['absence_code']);
        self::assertEquals(0, $day['fact_minutes']);
        self::assertSame(91, $day['absence_ground']['basis_document_id']);
        self::assertContains(['type' => 'business_trip', 'id' => 30, 'revision' => 2], $day['sources']);
    }

    public function test_draft_business_trip_is_visible_as_unapproved_source(): void
    {
        $employee = $this->employee();
        $employee['absences'] = [array_replace($this->trip(), ['status' => 'draft'])];
        $result = $this->calculate($employee);

        self::assertNull($result['rows'][0]['days'][1]['absence_code']);
        self::assertContains('business_trip_not_approved', array_column($result['issues'], 'code'));
        self::assertContains(['type' => 'business_trip', 'id' => 30, 'revision' => 2], $result['rows'][0]['days'][1]['sources']);
    }

    public function test_measured_work_on_trip_is_preserved_and_requires_matching_paper_review(): void
    {
        $employee = $this->employee();
        $employee['absences'] = [$this->trip()];
        $work = array_replace($this->absolute(), ['work_date' => '2026-09-02', 'start_at' => '2026-09-02T06:00:00Z', 'end_at' => '2026-09-02T14:00:00Z']);
        $employee['facts'] = [$work];
        $result = $this->calculate($employee);
        self::assertEquals(480, $result['summary']['fact_minutes']);
        self::assertContains('business_trip_work_review_required', array_column($result['issues'], 'code'));

        $employee['cells'] = [['id' => 9, 'revision' => 3, 'employment_contract_id' => 11, 'work_date' => '2026-09-02', 'reason_code' => 'business_trip_work', 'basis_reference' => 'Приказ К-1', 'intervals' => [$work], 'review' => ['business_trip_work_document_id' => ['id' => 91, 'document_type' => 'business_trip_order']]]];
        $reviewed = $this->calculate($employee);
        self::assertEquals(480, $reviewed['summary']['fact_minutes']);
        self::assertSame('business_trip', $reviewed['rows'][0]['days'][1]['absence_code']);
        self::assertNotContains('business_trip_work_review_required', array_column($reviewed['issues'], 'code'));
        $employee['cells'][0]['review']['business_trip_work_document_id']['id'] = 92;
        self::assertContains('business_trip_work_review_required', array_column($this->calculate($employee)['issues'], 'code'));
    }

    private function absolute(): array
    {
        return ['source_type' => 'time_entry', 'source_id' => 1, 'work_date' => '2026-09-04', 'clock_source' => 'absolute', 'start_at' => '2026-09-04T23:30:00Z', 'end_at' => '2026-09-05T01:30:00Z', 'timezone' => 'UTC', 'timezone_requires_reconciliation' => true];
    }

    private function trip(): array
    {
        return ['id' => 30, 'revision' => 2, 'start_date' => '2026-09-02', 'end_date' => '2026-09-02', 'status' => 'approved', 'code' => 'business_trip', 'source_type' => 'business_trip', 'basis_reference' => 'Приказ К-1', 'basis_document_id' => 91];
    }

    private function calculate(array $employee): array
    {
        return (new MonthlyTimekeepingCalculator)->calculate('2026-09', [$employee], TimekeepingRules::snapshot());
    }

    private function employee(): array
    {
        return ['id' => 1, 'personnel_number' => 'E1', 'full_name' => 'Иванов Иван', 'relations' => [['id' => 11, 'contract_number' => 'MAIN', 'status' => 'active', 'start_date' => '2026-01-01', 'end_date' => null, 'relation_type' => 'employment', 'employment_mode' => 'main', 'timezone' => 'Europe/Moscow', 'weekly_norm_hours' => 40, 'job_share' => 1, 'calendar_profile' => 'ru-five-day', 'summed_accounting_period' => null]], 'facts' => [], 'cells' => [], 'plans' => [], 'absences' => []];
    }
}
