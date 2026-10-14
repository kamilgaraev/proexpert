<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\HR;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceDurationEvidence;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use PHPUnit\Framework\TestCase;

final class AttendanceDurationEvidenceTest extends TestCase
{
    public function test_qr_presence_does_not_confirm_worked_duration(): void
    {
        $fact = AttendanceDurationEvidence::resolve(null, null, true, false);
        $this->assertFalse($fact['known']);
        $this->assertTrue($fact['presence_confirmed']);
        $this->assertNull($fact['confirmed_hours']);
    }

    public function test_manual_presence_without_hours_remains_unknown(): void
    {
        $fact = AttendanceDurationEvidence::resolve('at_work', null, false, false);
        $this->assertFalse($fact['known']);
        $this->assertTrue($fact['presence_confirmed']);
        $this->assertNull($fact['confirmed_hours']);
    }

    public function test_quantified_manual_evidence_is_used_without_schedule_fallback(): void
    {
        $fact = AttendanceDurationEvidence::resolve('at_work', '6.5', true, false);
        $this->assertTrue($fact['known']);
        $this->assertSame('6.5', $fact['confirmed_hours']);
    }

    public function test_no_factual_source_does_not_prove_unexplained_absence(): void
    {
        $fact = AttendanceDurationEvidence::resolve(null, null, false, false);
        $this->assertFalse($fact['known']);
        $this->assertFalse($fact['presence_confirmed']);
        $this->assertNull($fact['confirmed_hours']);
    }

    public function test_approved_absence_and_manual_zero_remain_quantified_zero(): void
    {
        $this->assertSame('0', AttendanceDurationEvidence::resolve(null, null, false, true)['confirmed_hours']);
        $this->assertSame('0', AttendanceDurationEvidence::resolve('at_work', 0, false, false)['confirmed_hours']);
        $this->assertTrue(AttendanceDurationEvidence::resolve('at_work', 0, false, false)['known']);
    }

    public function test_report_totals_do_not_turn_unknown_duration_into_schedule_hours_or_execution_percent(): void
    {
        $adapter = (new \ReflectionClass(DatabaseWorkforceReportAdapter::class))->newInstanceWithoutConstructor();
        $totals = (new \ReflectionMethod($adapter, 'attendanceTotals'))->invoke($adapter, [[
            'eligible_hours' => '8', 'present_hours' => '0', 'approved_absence_hours' => '0',
            'unexplained_absence_hours' => '0', 'overtime_hours' => '0', 'actual_duration_known' => false,
        ]]);
        $this->assertSame('8.00', $totals['eligible_hours']);
        $this->assertSame('0.00', $totals['present_hours']);
        $this->assertSame(1, $totals['unknown_duration_rows']);
        $this->assertFalse($totals['actual_duration_known']);
        $this->assertNull($totals['confirmed_hours']);
        $this->assertNull($totals['execution_percent']);
        $this->assertSame('0.00', $totals['unexplained_absence_hours']);
    }

    public function test_confirmed_worked_total_includes_overtime_instead_of_the_capped_execution_metric(): void
    {
        $adapter = (new \ReflectionClass(DatabaseWorkforceReportAdapter::class))->newInstanceWithoutConstructor();
        $totals = (new \ReflectionMethod($adapter, 'attendanceTotals'))->invoke($adapter, [[
            'eligible_hours' => '8', 'present_hours' => '8', 'confirmed_hours' => '12', 'approved_absence_hours' => '0',
            'unexplained_absence_hours' => '0', 'overtime_hours' => '4', 'actual_duration_known' => true,
        ]]);
        $this->assertSame('8.00', $totals['eligible_hours']);
        $this->assertSame('12.00', $totals['confirmed_hours']);
        $this->assertSame('4.00', $totals['overtime_hours']);
        $this->assertTrue($totals['actual_duration_known']);
    }
}
