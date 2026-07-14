<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Exceptions\Billing\StaleCommercialOfferException;
use App\Services\Billing\CommercialOfferCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class CommercialOfferCalculatorTest extends TestCase
{
    private const PACKAGES = [
        'projects-processes',
        'planning-schedules',
        'estimates-norms',
        'quality-safety',
        'pto-handover',
        'supply-warehouse',
        'finance-contracts',
        'workforce-output',
        'machinery',
        'sales-contractors',
    ];

    public function refreshDatabase(): void {}

    public function test_empty_selection_is_free_base(): void
    {
        $quote = $this->calculator()->preview([]);

        $this->assertSame('packages', $quote['offer_type']);
        $this->assertSame(0.0, $quote['monthly_total']);
        $this->assertSame(0.0, $quote['amount_due_now']);
        $this->assertSame([], $quote['target_package_slugs']);
        $this->assertSame('RUB', $quote['currency']);
        $this->assertSame(30, $quote['billing_period_days']);
    }

    public function test_single_package_uses_exact_catalog_price(): void
    {
        $quote = $this->calculator()->preview(['estimates-norms']);

        $this->assertSame(12900.0, $quote['monthly_total']);
        $this->assertSame(12900.0, $quote['amount_due_now']);
        $this->assertSame(['estimates-norms'], $quote['added_package_slugs']);
    }

    public function test_seven_packages_do_not_recommend_full_suite(): void
    {
        $quote = $this->calculator()->preview(array_slice(self::PACKAGES, 0, 7));

        $this->assertNull($quote['recommendation']);
        $this->assertSame('packages', $quote['offer_type']);
    }

    public function test_eight_packages_recommend_but_do_not_enable_full_suite(): void
    {
        $quote = $this->calculator()->preview(array_slice(self::PACKAGES, 0, 8));

        $this->assertSame('full_suite', $quote['recommendation']);
        $this->assertSame('packages', $quote['offer_type']);
        $this->assertCount(8, $quote['target_package_slugs']);
    }

    public function test_explicit_full_suite_contains_all_catalog_packages_and_discount(): void
    {
        $quote = $this->calculator()->preview([], fullSuite: true);

        $this->assertSame('full_suite', $quote['offer_type']);
        $this->assertSame(self::PACKAGES, $quote['target_package_slugs']);
        $this->assertSame(79900.0, $quote['monthly_total']);
        $this->assertSame(79900.0, $quote['amount_due_now']);
        $this->assertSame(23100.0, $quote['savings_amount']);
        $this->assertSame(22.43, $quote['savings_percent']);
    }

    public function test_duplicate_slugs_do_not_duplicate_price(): void
    {
        $quote = $this->calculator()->preview([
            'estimates-norms',
            ' estimates-norms ',
            'estimates-norms',
        ]);

        $this->assertSame(['estimates-norms'], $quote['target_package_slugs']);
        $this->assertSame(12900.0, $quote['monthly_total']);
    }

    public function test_unknown_slug_and_stale_quote_version_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->preview(['unknown-package']);
    }

    public function test_stale_quote_version_is_rejected_separately(): void
    {
        $this->expectException(StaleCommercialOfferException::class);
        $this->calculator()->assertCurrentQuoteVersion(0);
    }

    public function test_new_period_charges_full_price_and_sets_fixed_thirty_day_period(): void
    {
        $now = CarbonImmutable::parse('2026-07-14 10:00:00', 'UTC');
        $quote = $this->calculator()->preview(['planning-schedules'], calculatedAt: $now);

        $this->assertSame(7900.0, $quote['amount_due_now']);
        $this->assertTrue($now->equalTo($quote['period_start_at']));
        $this->assertTrue($now->addDays(30)->equalTo($quote['period_end_at']));
    }

    public function test_package_added_after_six_days_is_charged_for_exact_remaining_seconds(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');
        $end = $start->addDays(30);
        $quote = $this->calculator()->preview(
            ['planning-schedules', 'estimates-norms'],
            ['planning-schedules'],
            calculatedAt: $start->addDays(6),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $end,
        );

        $this->assertSame(10320.0, $quote['amount_due_now']);
        $this->assertTrue($start->equalTo($quote['period_start_at']));
        $this->assertTrue($end->equalTo($quote['period_end_at']));
    }

    public function test_removal_has_no_immediate_refund(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');
        $quote = $this->calculator()->preview(
            ['planning-schedules'],
            ['planning-schedules', 'estimates-norms'],
            calculatedAt: $start->addDays(6),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $start->addDays(30),
        );

        $this->assertSame(0.0, $quote['amount_due_now']);
        $this->assertSame(['estimates-norms'], $quote['removed_package_slugs']);
    }

    public function test_full_suite_upgrade_charges_only_positive_prorated_difference(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');
        $quote = $this->calculator()->preview(
            [],
            array_slice(self::PACKAGES, 0, 7),
            fullSuite: true,
            calculatedAt: $start->addDays(6),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $start->addDays(30),
        );

        $this->assertSame(2080.0, $quote['amount_due_now']);
    }

    public function test_existing_period_must_be_exactly_thirty_days(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');

        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->preview(
            ['planning-schedules'],
            calculatedAt: $start->addDay(),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $start->addDays(29),
        );
    }

    private function calculator(): CommercialOfferCalculator
    {
        return app(CommercialOfferCalculator::class);
    }
}
