<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Timekeeping;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingLaborReview;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingRules;
use PHPUnit\Framework\TestCase;

final class TimekeepingLaborReviewTest extends TestCase
{
    public function test_annual_hour_121_crossing_is_double_and_240_needs_explicit_agreement(): void
    {
        $date = '2026-09-02';
        $days = [$date => $this->day($date, 660)];
        $review = ['annual_minutes_before_month' => 7140, 'annual_baseline_document_id' => ['id' => 1], 'overtime_consent_document_id' => ['id' => 2], 'overtime_order_document_id' => ['id' => 3], 'collective_agreement_document_id' => ['id' => 4]];
        (new TimekeepingLaborReview)->apply($days, [$date => $this->relation()], [['work_date' => $date, 'review' => $review]], TimekeepingRules::snapshot(['legal_review_required' => false]), 1, '2026-09');
        self::assertEquals(180, $days[$date]['overtime_minutes']);
        self::assertEquals(7320, $days[$date]['overtime_annual_minutes']);
        self::assertSame([['minutes' => 60, 'minimum_multiplier' => 1.5], ['minutes' => 120, 'minimum_multiplier' => 2.0]], $days[$date]['overtime_payment_bands']);
        self::assertNotContains('overtime_annual_limit_exceeded', array_column($days[$date]['issues'], 'code'));
        unset($review['collective_agreement_document_id']);
        $days = [$date => $this->day($date, 660)];
        (new TimekeepingLaborReview)->apply($days, [$date => $this->relation()], [['work_date' => $date, 'review' => $review]], TimekeepingRules::snapshot(['legal_review_required' => false]), 1, '2026-09');
        self::assertContains('overtime_annual_limit_exceeded', array_column($days[$date]['issues'], 'code'));
        self::assertEquals(660, $days[$date]['fact_minutes']);
    }

    public function test_before_september_limit_applies_to_two_consecutive_days_after_to_each_day(): void
    {
        $days = ['2026-08-03' => $this->day('2026-08-03', 660), '2026-08-04' => $this->day('2026-08-04', 600)];
        (new TimekeepingLaborReview)->apply($days, array_fill_keys(array_keys($days), $this->relation()), [], TimekeepingRules::snapshot(), 1, '2026-08');
        self::assertContains('overtime_two_consecutive_days_limit_exceeded', array_column($days['2026-08-04']['issues'], 'code'));
        $days = ['2026-09-02' => $this->day('2026-09-02', 660), '2026-09-03' => $this->day('2026-09-03', 600)];
        (new TimekeepingLaborReview)->apply($days, array_fill_keys(array_keys($days), $this->relation()), [], TimekeepingRules::snapshot(), 1, '2026-09');
        self::assertNotContains('overtime_two_consecutive_days_limit_exceeded', array_column($days['2026-09-03']['issues'], 'code'));
        self::assertNotContains('overtime_daily_limit_exceeded', array_column($days['2026-09-03']['issues'], 'code'));
    }

    public function test_harmful_33_keeps_120_hours_cap_even_with_collective_agreement(): void
    {
        $date = '2026-09-02';
        $days = [$date => $this->day($date, 660)];
        $relation = $this->relation();
        $relation['metadata']['sout_class'] = '3.3';
        $review = ['annual_minutes_before_month' => 7140, 'annual_baseline_document_id' => ['id' => 1], 'collective_agreement_document_id' => ['id' => 4]];
        (new TimekeepingLaborReview)->apply($days, [$date => $relation], [['work_date' => $date, 'review' => $review]], TimekeepingRules::snapshot(), 1, '2026-09');
        self::assertContains('overtime_annual_limit_exceeded', array_column($days[$date]['issues'], 'code'));
    }

    private function relation(): array
    {
        return ['relation_type' => 'employment', 'summed_accounting_period' => null, 'metadata' => []];
    }

    private function day(string $date, int $actual): array
    {
        return ['work_date' => $date, 'norm_minutes' => 480, 'planned_minutes' => 480, 'fact_minutes' => $actual, 'fact_microseconds' => $actual * 60000000, 'weekend_minutes' => 0, 'weekend_microseconds' => 0, 'night_minutes' => 0, 'overtime_minutes' => 0, 'issues' => []];
    }
}
