<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Credits\AssistantRevenueAllocation;
use App\Services\Billing\CommercialOfferCalculator;
use App\Services\Modules\PackageCatalogService;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class AssistantRevenueAllocationTest extends TestCase
{
    public function test_initial_bundle_has_internal_share_and_resources_do_not_inflate_it(): void
    {
        $snapshot = $this->service()->snapshot($this->quote(), $this->start(), null, null, 4_090_000);
        self::assertSame('declared_internal_allocation', $snapshot['basis']);
        self::assertSame(399_000, $snapshot['amount_minor']);
        self::assertSame(399_000, AssistantRevenueAllocation::verify($snapshot, 4_090_000, 4_090_000));
        self::assertSame(3_990_000, $snapshot['recurring_payable_minor']);
        self::assertSame('utf8_lf', $snapshot['source_normalization']);
    }

    public function test_full_suite_discount_is_applied_proportionally_without_changing_quote(): void
    {
        $quote = $this->quote(['working-entry', 'finance-contracts'], [], 'full_suite', 2_995_000);
        $before = $quote;
        $snapshot = $this->service()->snapshot($quote, $this->start(), null, null, 2_995_000);
        self::assertSame(199_500, $snapshot['amount_minor']);
        self::assertSame($before, $quote);
    }

    public function test_upgrade_with_existing_ai_is_zero_even_when_more_packages_are_paid(): void
    {
        $quote = $this->quote(['working-entry', 'finance-contracts'], ['working-entry']);
        $quote['amount_due_now_minor'] = 1_000_000;
        $snapshot = $this->service()->snapshot($quote, $this->start()->addDays(15), $this->start(), $this->end(), 1_000_000);
        self::assertSame(0, $snapshot['amount_minor']);
        self::assertTrue($snapshot['is_upgrade']);
        self::assertSame(0, AssistantRevenueAllocation::verify($snapshot, 1_000_000, 1_000_000));
    }

    public function test_new_ai_upgrade_uses_same_remaining_seconds_and_floor(): void
    {
        $quote = $this->quote(['working-entry', 'finance-contracts'], ['finance-contracts']);
        $quote['amount_due_now_minor'] = 1_995_000;
        $snapshot = $this->service()->snapshot($quote, $this->start()->addDays(15), $this->start(), $this->end(), 1_995_000);
        self::assertSame(199_500, $snapshot['amount_minor']);
        self::assertSame(1_296_000, $snapshot['period_ratio_numerator']);
        self::assertFalse($snapshot['current_ai']);
    }

    public function test_full_suite_upgrade_share_is_capped_at_actual_positive_recurring_payment(): void
    {
        $quote = $this->quote(['working-entry', 'finance-contracts'], ['finance-contracts'], 'full_suite', 2_000_001);
        $quote['amount_due_now_minor'] = 1;
        $snapshot = $this->service()->snapshot($quote, $this->start()->addDays(15), $this->start(), $this->end(), 100_001);
        self::assertSame(1, $snapshot['amount_minor']);
        self::assertSame(1, $snapshot['recurring_payable_minor']);
    }

    public function test_renewal_allocates_new_full_period_even_when_ai_already_exists(): void
    {
        $quote = $this->quote(['working-entry'], ['working-entry']);
        $quote['amount_due_now_minor'] = 0;
        $snapshot = $this->service()->snapshot($quote, $this->start()->addDays(29), $this->start(), $this->end(), 3_990_000, true, $this->end(), $this->end()->addDays(30));
        self::assertSame(399_000, $snapshot['amount_minor']);
        self::assertSame('renewal', $snapshot['mode']);
        self::assertSame($this->end()->toAtomString(), $snapshot['period_start_at']);
        self::assertFalse($snapshot['is_upgrade']);
        self::assertSame(399_000, AssistantRevenueAllocation::verify($snapshot, 3_990_000, 3_990_000));
    }

    public function test_no_ai_and_historical_missing_snapshot_never_create_revenue(): void
    {
        $snapshot = $this->service()->snapshot($this->quote(['finance-contracts']), $this->start(), null, null, 2_000_000);
        self::assertSame(0, $snapshot['amount_minor']);
        self::assertNull(AssistantRevenueAllocation::verify([], 3_990_000, 3_990_000));
    }

    public function test_unsupported_and_inconsistent_quotes_are_unknown(): void
    {
        self::assertSame('unknown', $this->service(__DIR__.'/missing-module-manifest.json')->snapshot($this->quote(), $this->start(), null, null, 3_990_000)['status']);
        foreach (['currency' => 'USD', 'monthly_total_minor' => 1, 'amount_due_now_minor' => 1, 'billing_period_days' => 31, 'target_package_slugs' => ['missing'], 'period_end_at' => $this->end()->addDay()] as $field => $value) {
            $quote = $this->quote();
            $quote[$field] = $value;
            $snapshot = $this->service()->snapshot($quote, $this->start(), null, null, 3_990_000);
            self::assertSame('unknown', $snapshot['status'], $field);
            self::assertNull(AssistantRevenueAllocation::verify($snapshot, 3_990_000, 3_990_000));
        }
    }

    public function test_tampered_amount_policy_period_or_payment_cannot_be_used(): void
    {
        $snapshot = $this->service()->snapshot($this->quote(), $this->start(), null, null, 3_990_000);
        foreach (['amount_minor' => 399_001, 'policy_version' => 2, 'standalone_amount_minor' => 4_000_000, 'period_ratio_numerator' => 2_592_001, 'period_end_at' => $this->end()->addDay()->toAtomString(), 'policy_sha256' => str_repeat('0', 64)] as $field => $value) {
            $changed = $snapshot;
            $changed[$field] = $value;
            self::assertNull(AssistantRevenueAllocation::verify($changed, 3_990_000, 3_990_000), $field);
        }
        self::assertNull(AssistantRevenueAllocation::verify($snapshot, 1, 3_990_000));
    }

    public function test_cumulative_actual_refunds_reduce_share_once_and_full_refund_is_zero(): void
    {
        $snapshot = $this->service()->snapshot($this->quote(), $this->start(), null, null, 3_990_000);
        self::assertSame(199_500, AssistantRevenueAllocation::netAfterRefund($snapshot, 3_990_000, 3_990_000, 1_995_000));
        self::assertSame(199_500, AssistantRevenueAllocation::netAfterRefund($snapshot, 3_990_000, 3_990_000, 1_995_000));
        self::assertSame(0, AssistantRevenueAllocation::netAfterRefund($snapshot, 3_990_000, 3_990_000, 3_990_000));
        self::assertNull(AssistantRevenueAllocation::netAfterRefund($snapshot, 3_990_000, 3_990_000, -1));
        self::assertNull(AssistantRevenueAllocation::netAfterRefund($snapshot, 3_990_000, 3_990_000, 3_990_001));
    }

    public function test_manifest_hash_normalizes_line_endings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'assistant-allocation-');
        self::assertNotFalse($path);
        try {
            $contents = file_get_contents(dirname(__DIR__, 3).'/config/ModuleList/addons/ai-assistant.json');
            self::assertIsString($contents);
            file_put_contents($path, str_replace("\n", "\r\n", str_replace("\r\n", "\n", $contents)));
            $lf = $this->service()->snapshot($this->quote(), $this->start(), null, null, 3_990_000);
            $crlf = $this->service($path)->snapshot($this->quote(), $this->start(), null, null, 3_990_000);
            self::assertSame($lf['module_source_sha256'], $crlf['module_source_sha256']);
            self::assertSame($lf['policy_sha256'], $crlf['policy_sha256']);
            self::assertSame($lf['snapshot_sha256'], $crlf['snapshot_sha256']);
        } finally { unlink($path); }
    }

    public function test_actual_server_calculator_proration_matches_allocation_basis(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['commercial_offers' => ['quote_version' => 1, 'currency' => 'RUB', 'billing_period_days' => 30]]));
        Container::setInstance($container);
        try {
            $catalog = $this->catalog();
            $catalog->method('entryPackageSlug')->willReturn('working-entry');
            $catalog->method('canonicalizePackageSlug')->willReturnArgument(0);
            $at = $this->start()->addDays(15)->addSecond();
            $quote = (new CommercialOfferCalculator($catalog))->preview(['working-entry', 'finance-contracts'], ['finance-contracts'], false, $at, $this->start(), $this->end());
            $snapshot = (new AssistantRevenueAllocation($catalog))->snapshot($quote, $at, $this->start(), $this->end(), $quote['amount_due_now_minor']);
            self::assertSame(1_994_998, $quote['amount_due_now_minor']);
            self::assertSame(199_499, $snapshot['amount_minor']);
            self::assertSame(1_295_999, $snapshot['period_ratio_numerator']);
            self::assertSame(199_499, AssistantRevenueAllocation::verify($snapshot, $quote['amount_due_now_minor'], $quote['amount_due_now_minor']));
        } finally { Container::setInstance($previous); }
    }

    private function service(?string $manifestPath = null): AssistantRevenueAllocation
    {
        return new AssistantRevenueAllocation($this->catalog(), $manifestPath);
    }

    private function catalog(): PackageCatalogService
    {
        $catalog = $this->createMock(PackageCatalogService::class);
        $catalog->method('allPackages')->willReturn([['slug' => 'working-entry', 'tiers' => ['standard' => ['price' => 39900]]], ['slug' => 'finance-contracts', 'tiers' => ['standard' => ['price' => 20000]]]]);
        $catalog->method('tierModules')->willReturnCallback(static fn (string $slug): array => $slug === 'working-entry' ? ['ai-assistant'] : ['budgeting']);
        return $catalog;
    }

    private function quote(array $target = ['working-entry'], array $current = [], string $type = 'packages', ?int $monthly = null): array
    {
        $prices = ['working-entry' => 3_990_000, 'finance-contracts' => 2_000_000];
        $monthly ??= array_sum(array_map(static fn (string $slug): int => $prices[$slug], $target));
        return ['currency' => 'RUB', 'billing_period_days' => 30, 'offer_type' => $type, 'quote_version' => 1, 'target_package_slugs' => $target, 'current_package_slugs' => $current, 'monthly_total_minor' => $monthly, 'amount_due_now_minor' => $monthly, 'period_start_at' => $this->start(), 'period_end_at' => $this->end()];
    }

    private function start(): CarbonImmutable { return CarbonImmutable::parse('2026-09-01T00:00:00Z'); }
    private function end(): CarbonImmutable { return $this->start()->addDays(30); }
}
