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
        'working-entry',
        'supply-warehouse',
        'finance-contracts',
        'quality-safety',
        'workforce-output',
        'machinery',
        'sales-contractors',
    ];

    public function refreshDatabase(): void {}

    public function test_empty_selection_is_free_base(): void
    {
        $quote = $this->calculator()->preview([]);

        $this->assertSame('packages', $quote['offer_type']);
        $this->assertSame('0.00', $quote['monthly_total']);
        $this->assertSame('0.00', $quote['amount_due_now']);
        $this->assertSame(0, $quote['amount_due_now_minor']);
        $this->assertSame([], $quote['target_package_slugs']);
        $this->assertSame('RUB', $quote['currency']);
        $this->assertSame(30, $quote['billing_period_days']);
    }

    public function test_single_package_uses_exact_catalog_price(): void
    {
        $quote = $this->calculator()->preview(['working-entry']);

        $this->assertSame('39900.00', $quote['monthly_total']);
        $this->assertSame('39900.00', $quote['amount_due_now']);
        $this->assertSame(3990000, $quote['amount_due_now_minor']);
        $this->assertSame(['working-entry'], $quote['added_package_slugs']);
    }

    public function test_selection_below_threshold_does_not_recommend_full_suite(): void
    {
        $quote = $this->calculator()->preview(['working-entry', 'machinery', 'quality-safety']);

        $this->assertNull($quote['recommendation']);
        $this->assertSame('52700.00', $quote['monthly_total']);
        $this->assertSame('packages', $quote['offer_type']);
    }

    public function test_selection_at_threshold_recommends_but_does_not_enable_full_suite(): void
    {
        $quote = $this->calculator()->preview(['working-entry', 'supply-warehouse', 'finance-contracts', 'workforce-output']);

        $this->assertSame('full_suite', $quote['recommendation']);
        $this->assertSame('packages', $quote['offer_type']);
        $this->assertSame('67600.00', $quote['monthly_total']);
        $this->assertCount(4, $quote['target_package_slugs']);
    }

    public function test_contour_without_entry_includes_entry_price(): void
    {
        $quote = $this->calculator()->preview(['machinery']);

        $this->assertSame(['working-entry', 'machinery'], $quote['target_package_slugs']);
        $this->assertSame('45800.00', $quote['monthly_total']);
    }

    public function test_retired_entry_slug_resolves_to_working_entry(): void
    {
        $quote = $this->calculator()->preview(['estimates-norms', 'estimates-norms']);
        $retiredPto = $this->calculator()->preview(['pto-handover']);

        $this->assertSame(['working-entry'], $quote['target_package_slugs']);
        $this->assertSame('39900.00', $quote['monthly_total']);
        $this->assertSame(['working-entry'], $retiredPto['target_package_slugs']);
        $this->assertSame('39900.00', $retiredPto['monthly_total']);
    }

    public function test_explicit_full_suite_contains_all_catalog_packages_and_discount(): void
    {
        $quote = $this->calculator()->preview([], fullSuite: true);

        $this->assertSame('full_suite', $quote['offer_type']);
        $this->assertSame(self::PACKAGES, $quote['target_package_slugs']);
        $this->assertSame('79900.00', $quote['monthly_total']);
        $this->assertSame('79900.00', $quote['amount_due_now']);
        $this->assertSame('8400.00', $quote['savings_amount']);
        $this->assertSame(9.51, $quote['savings_percent']);
    }

    public function test_duplicate_slugs_do_not_duplicate_price(): void
    {
        $quote = $this->calculator()->preview([
            'working-entry',
            ' working-entry ',
            'working-entry',
        ]);

        $this->assertSame(['working-entry'], $quote['target_package_slugs']);
        $this->assertSame('39900.00', $quote['monthly_total']);
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
        $quote = $this->calculator()->preview(['working-entry'], calculatedAt: $now);

        $this->assertSame('39900.00', $quote['amount_due_now']);
        $this->assertTrue($now->equalTo($quote['period_start_at']));
        $this->assertTrue($now->addDays(30)->equalTo($quote['period_end_at']));
    }

    public function test_package_added_after_six_days_is_charged_for_exact_remaining_seconds(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');
        $end = $start->addDays(30);
        $quote = $this->calculator()->preview(
            ['working-entry', 'supply-warehouse'],
            ['working-entry'],
            calculatedAt: $start->addDays(6),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $end,
        );

        $this->assertSame('7920.00', $quote['amount_due_now']);
        $this->assertTrue($start->equalTo($quote['period_start_at']));
        $this->assertTrue($end->equalTo($quote['period_end_at']));
    }

    public function test_removal_has_no_immediate_refund(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');
        $quote = $this->calculator()->preview(
            ['working-entry'],
            ['working-entry', 'supply-warehouse'],
            calculatedAt: $start->addDays(6),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $start->addDays(30),
        );

        $this->assertSame('0.00', $quote['amount_due_now']);
        $this->assertSame(['supply-warehouse'], $quote['removed_package_slugs']);
    }

    public function test_full_suite_upgrade_charges_only_positive_prorated_difference(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');
        $quote = $this->calculator()->preview(
            [],
            ['working-entry'],
            fullSuite: true,
            calculatedAt: $start->addDays(6),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $start->addDays(30),
        );

        $this->assertSame('32000.00', $quote['amount_due_now']);
    }

    public function test_existing_period_must_be_exactly_thirty_days(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');

        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->preview(
            ['working-entry'],
            calculatedAt: $start->addDay(),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $start->addDays(29),
        );
    }

    public function test_existing_period_requires_both_boundaries(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');

        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->preview(
            ['working-entry'],
            calculatedAt: $start->addDay(),
            currentPeriodStartAt: $start,
        );
    }

    public function test_existing_period_rejects_microsecond_over_thirty_days(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00.000000', 'UTC');

        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->preview(
            ['working-entry'],
            calculatedAt: $start->addDay(),
            currentPeriodStartAt: $start,
            currentPeriodEndAt: $start->addDays(30)->addMicrosecond(),
        );
    }

    public function test_six_calendar_months_have_five_percent_discount_and_no_month_overflow(): void
    {
        $now = CarbonImmutable::parse('2026-08-31 09:00:00', 'Europe/Moscow');
        $quote = $this->calculator()->preview(['working-entry'], calculatedAt: $now, billingPeriod: '6_months');
        $this->assertSame(22743000, $quote['amount_due_now_minor']);
        $this->assertSame(5, $quote['period_discount_percent']);
        $this->assertSame('2027-02-28 09:00:00', $quote['period_end_at']->format('Y-m-d H:i:s'));
    }

    public function test_annual_full_suite_discount_combines_with_bundle_price_and_handles_leap_day(): void
    {
        $now = CarbonImmutable::parse('2028-02-29 09:00:00', 'Europe/Moscow');
        $quote = $this->calculator()->preview([], fullSuite: true, calculatedAt: $now, billingPeriod: '12_months');
        $this->assertSame(86292000, $quote['amount_due_now_minor']);
        $this->assertSame(9588000, $quote['period_savings_minor']);
        $this->assertSame('2029-02-28 09:00:00', $quote['period_end_at']->format('Y-m-d H:i:s'));
    }

    public function test_prepaid_annual_renewal_starts_at_current_expiry(): void
    {
        $start = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
        $end = $start->addDays(30);
        $quote = $this->calculator()->preview(['working-entry'], ['working-entry'], calculatedAt: $start->addDay(), currentPeriodStartAt: $start, currentPeriodEndAt: $end, billingPeriod: '12_months', renewSubscription: true);
        $this->assertSame(43092000, $quote['amount_due_now_minor']);
        $this->assertTrue($end->equalTo($quote['period_start_at']));
        $this->assertTrue($end->addYear()->equalTo($quote['period_end_at']));
    }

    public function test_upgrade_during_an_annual_period_uses_annual_discount_and_actual_remaining_duration(): void
    {
        $start = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
        $end = $start->addYear();
        $quote = $this->calculator()->preview(['working-entry', 'supply-warehouse'], ['working-entry'], calculatedAt: $start->addDays(100), currentPeriodStartAt: $start, currentPeriodEndAt: $end, billingPeriod: '12_months', currentBillingPeriod: '12_months');
        $this->assertSame((int) round(10692000 * 265 / 365), $quote['amount_due_now_minor']);
        $this->assertTrue($end->equalTo($quote['period_end_at']));
    }

    public function test_billing_period_change_without_renewal_is_rejected(): void
    {
        $start = CarbonImmutable::parse('2026-10-15', 'UTC');
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->preview(['working-entry'], ['working-entry'], calculatedAt: $start->addDay(), currentPeriodStartAt: $start, currentPeriodEndAt: $start->addDays(30), billingPeriod: '6_months');
    }

    public function test_renewal_cannot_remove_or_add_packages_before_the_paid_period_ends(): void
    {
        $start = CarbonImmutable::parse('2026-10-15', 'UTC');
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->preview(['working-entry', 'machinery'], ['working-entry'], calculatedAt: $start->addDay(), currentPeriodStartAt: $start, currentPeriodEndAt: $start->addDays(30), billingPeriod: '12_months', renewSubscription: true);
    }

    public function test_calendar_period_uses_moscow_timezone_at_the_utc_month_boundary(): void
    {
        $now = CarbonImmutable::parse('2026-08-30 21:30:00', 'UTC');
        $quote = $this->calculator()->preview(['working-entry'], calculatedAt: $now, billingPeriod: '6_months');
        $this->assertSame('2027-02-28 00:30:00', $quote['period_end_at']->setTimezone('Europe/Moscow')->format('Y-m-d H:i:s'));
    }

    public function test_resource_addons_cover_the_selected_period_without_the_package_discount(): void
    {
        $quote = $this->calculator()->preview(['working-entry'], billingPeriod: '6_months');
        $resourceQuote = \App\Services\Billing\CommercialResourcePeriodPricing::apply([
            'amount_minor' => 30000,
            'items' => [['amount_minor' => 30000]],
        ], $quote);
        $this->assertSame(180000, $resourceQuote['amount_minor']);
        $this->assertSame('1800.00', $resourceQuote['amount']);
    }

    private function calculator(): CommercialOfferCalculator
    {
        return app(CommercialOfferCalculator::class);
    }
}
