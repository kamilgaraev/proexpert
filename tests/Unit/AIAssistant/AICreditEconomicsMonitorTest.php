<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Services\Credits\AICreditEconomicsMonitor;
use App\Services\Credits\AssistantRevenueAllocation;
use App\Services\Modules\PackageCatalogService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AICreditEconomicsMonitorTest extends TestCase
{
    public function test_deduplicates_linked_chat_and_adds_ocr_embeddings_and_failed_actual_costs(): void
    {
        $canonical = [$this->cost('call-1', 10000), $this->cost('ocr-1', 20000, 'ocr'),
            $this->cost('failed-1', 2000) + ['is_successful' => false]];
        $legacy = [$this->legacy('assistant_chat', '0.900000', ['credit_usage_key' => 'call-1']),
            $this->legacy('rag_index', '0.004000', ['usage_key' => 'embedding-1', 'cost_available' => true]),
            $this->legacy('rag_query', '0.003000', ['usage_key' => 'embedding-2', 'cost_available' => true])];
        $report = (new AICreditEconomicsMonitor)->evaluate($canonical, $legacy, [$this->payment(1000)], []);
        self::assertSame(39000, $report['external_cost_micro_rub']);
        self::assertSame(1, $report['deduplicated_usage_count']);
        self::assertSame(5, $report['provider_call_count']);
        self::assertSame(0, $report['unknown_cost_count']);
        self::assertSame('within_30_percent', $report['state']);
        self::assertSame(0.0039, $report['external_cost_revenue_ratio']);
    }

    public function test_unavailable_provider_usage_and_unlinked_legacy_chat_are_incomplete(): void
    {
        $cost = $this->cost('unknown', 0);
        $cost['metadata'] = ['cost_available' => false, 'provider_usage_available' => false];
        $report = (new AICreditEconomicsMonitor)->evaluate([$cost], [$this->legacy('assistant_chat', '1.000000')], [], []);
        self::assertSame(2, $report['unknown_cost_count']);
        self::assertSame(0, $report['external_cost_micro_rub']);
        self::assertNull($report['external_cost_revenue_ratio']);
        self::assertSame('coverage_incomplete', $report['state']);
    }

    public function test_estimate_generation_is_excluded_and_explicit_free_call_is_known(): void
    {
        $report = (new AICreditEconomicsMonitor)->evaluate([$this->cost('estimate', 100000, 'estimate_generation_dialogue'), $this->cost('free', 0)],
            [$this->legacy('estimate_generation', '2.000000')], [], []);
        self::assertSame(0, $report['external_cost_micro_rub']);
        self::assertSame(2, $report['excluded_estimate_generation_count']);
        self::assertSame(0, $report['unknown_cost_count']);
        self::assertSame('awaiting_actual_revenue', $report['state']);
    }

    public function test_refunds_are_not_double_counted_against_cumulative_payment_refund(): void
    {
        $payment = $this->payment(1000);
        $payment['refunded_amount_minor'] = 300;
        $refund = ['commercial_payment_id' => 1, 'provider_refund_id' => 'refund-1', 'provider_status' => 'succeeded', 'amount_minor' => 300, 'currency' => 'RUB'];
        $report = (new AICreditEconomicsMonitor)->evaluate([], [], [$payment, $payment], [$refund, $refund]);
        self::assertSame(1000, $report['gross_assistant_revenue_minor']);
        self::assertSame(300, $report['assistant_refunds_minor']);
        self::assertSame(700, $report['assistant_revenue_minor']);
        self::assertSame(1, $report['settled_assistant_payment_count']);
    }

    public function test_full_refund_keeps_settlement_evidence_with_zero_net_revenue(): void
    {
        $payment = $this->payment(1000);
        $payment['order_status'] = 'refunded';
        $payment['refunded_amount_minor'] = 1000;
        $report = (new AICreditEconomicsMonitor)->evaluate([$this->cost('call', 500)], [], [$payment], []);
        self::assertSame(1000, $report['assistant_refunds_minor']);
        self::assertSame(0, $report['assistant_revenue_minor']);
        self::assertNull($report['external_cost_revenue_ratio']);
        self::assertSame('awaiting_actual_revenue', $report['state']);
    }

    public function test_bundle_price_and_missing_settlement_date_never_become_assistant_revenue(): void
    {
        $bundle = $this->payment(399000);
        $bundle['kind'] = 'renewal';
        $unknownDate = $this->payment(1000);
        $unknownDate['provider_payment_id'] = 'unknown-date';
        $unknownDate['settled_at'] = null;
        $report = (new AICreditEconomicsMonitor)->evaluate([], [], [$bundle, $unknownDate], []);
        self::assertSame(0, $report['assistant_revenue_minor']);
        self::assertSame(2, $report['unknown_allocation_count']);
        self::assertSame('coverage_incomplete', $report['state']);
    }

    public function test_exact_threshold_and_one_micro_ruble_above_it(): void
    {
        $monitor = new AICreditEconomicsMonitor;
        self::assertSame('within_30_percent', $monitor->evaluate([$this->cost('exact', 3000000)], [], [$this->payment(1000)], [])['state']);
        self::assertSame('exceeds_30_percent', $monitor->evaluate([$this->cost('above', 3000001)], [], [$this->payment(1000)], [])['state']);
    }

    public function test_verified_internal_allocation_is_disclosed_separately_and_refunded_proportionally(): void
    {
        $payment = $this->payment(1000000);
        $payment['kind'] = 'purchase';
        $payment['refunded_amount_minor'] = 250000;
        $payment['assistant_revenue_allocation'] = $this->allocation();
        $report = (new AICreditEconomicsMonitor)->evaluate([], [], [$payment], []);
        self::assertSame(0, $report['direct_assistant_cash_revenue_minor']);
        self::assertSame(299250, $report['internally_attributed_assistant_revenue_minor']);
        self::assertSame(299250, $report['assistant_revenue_minor']);
        self::assertSame(99750, $report['assistant_refunds_minor']);
        self::assertSame(750000, $report['total_confirmed_cohort_cash_minor']);
        self::assertSame(1, $report['internally_allocated_payment_count']);
        self::assertSame('within_30_percent', $report['state']);
    }

    public function test_tampered_internal_allocation_is_unknown_instead_of_actual_revenue(): void
    {
        $payment = $this->payment(1000000);
        $payment['kind'] = 'purchase';
        $payment['assistant_revenue_allocation'] = $this->allocation();
        $payment['assistant_revenue_allocation']['amount_minor']++;
        $report = (new AICreditEconomicsMonitor)->evaluate([], [], [$payment], []);
        self::assertSame(0, $report['assistant_revenue_minor']);
        self::assertSame(1, $report['unknown_allocation_count']);
        self::assertSame('coverage_incomplete', $report['state']);
    }

    public function test_call_keys_do_not_deduplicate_different_organizations(): void
    {
        $first = $this->cost('same-client-request', 100);
        $first['organization_id'] = 1;
        $second = $this->cost('same-client-request', 200);
        $second['organization_id'] = 2;
        $legacy = $this->legacy('assistant_chat', '1.000000', ['usage_key' => 'same-client-request']);
        $legacy['organization_id'] = 1;
        $report = (new AICreditEconomicsMonitor)->evaluate([$first, $second], [$legacy], [], []);
        self::assertSame(300, $report['external_cost_micro_rub']);
        self::assertSame(1, $report['deduplicated_usage_count']);
        self::assertSame(2, $report['provider_call_count']);
    }

    public function test_snapshot_for_another_order_period_is_not_attributed(): void
    {
        $payment = $this->payment(1000000);
        $payment['kind'] = 'purchase';
        $payment['assistant_revenue_allocation'] = $this->allocation();
        $payment['order_period_start_at'] = '2027-01-01T00:00:00+00:00';
        $payment['order_period_end_at'] = '2027-01-31T00:00:00+00:00';
        $report = (new AICreditEconomicsMonitor)->evaluate([], [], [$payment], []);
        self::assertSame(0, $report['assistant_revenue_minor']);
        self::assertSame(1, $report['unknown_allocation_count']);
    }

    public function test_proven_zero_ai_allocation_does_not_invent_a_settlement_date_or_cash_revenue(): void
    {
        $payment = $this->payment(1000000);
        $payment['kind'] = 'purchase';
        $payment['assistant_revenue_allocation'] = $this->allocation(false);
        $payment['settled_at'] = null;
        $report = (new AICreditEconomicsMonitor)->evaluate([], [], [$payment], []);
        self::assertSame(0, $report['assistant_revenue_minor']);
        self::assertSame(0, $report['total_confirmed_cohort_cash_minor']);
        self::assertSame(0, $report['unknown_allocation_count']);
        self::assertSame(1, $report['excluded_zero_ai_allocation_count']);
        self::assertSame('awaiting_actual_revenue', $report['state']);
    }

    public function test_unknown_cost_precision_and_missing_sources_are_not_zero_complete(): void
    {
        $report = (new AICreditEconomicsMonitor)->evaluate([], [$this->legacy('rag_index', '0.0000001')], [], [], 2);
        self::assertSame(1, $report['unknown_cost_count']);
        self::assertSame(2, $report['missing_source_count']);
        self::assertSame('coverage_incomplete', $report['state']);
    }

    public function test_integer_overflow_fails_instead_of_reporting_an_incorrect_margin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AICreditEconomicsMonitor)->evaluate([$this->cost('large', PHP_INT_MAX), $this->cost('extra', 1)], [], [], []);
    }

    private function cost(string $key, int $cost, string $operation = 'assistant_chat'): array
    {
        return ['usage_key' => $key, 'provider' => 'timeweb', 'model' => 'luna', 'operation' => $operation,
            'cost_micro_rub' => $cost, 'metadata' => ['cost_available' => true]];
    }

    private function legacy(string $operation, string $cost, array $metadata = []): array
    {
        return ['provider' => 'timeweb', 'model' => 'luna', 'operation' => $operation, 'total_cost_rub' => $cost, 'metadata' => $metadata];
    }

    private function payment(int $amount): array
    {
        return ['id' => 1, 'provider_payment_id' => 'payment-1', 'provider_status' => 'succeeded', 'order_status' => 'paid', 'kind' => 'ai_credits',
            'amount_minor' => $amount, 'order_amount_minor' => $amount, 'refunded_amount_minor' => 0, 'currency' => 'RUB', 'order_currency' => 'RUB'];
    }

    private function allocation(bool $includesAi = true): array
    {
        $catalog = $this->createMock(PackageCatalogService::class);
        $catalog->method('allPackages')->willReturn([['slug' => 'economics-test', 'tiers' => ['standard' => ['price' => 10000]]]]);
        $catalog->method('tierModules')->willReturn($includesAi ? ['ai-assistant'] : []);
        $now = CarbonImmutable::parse('2026-09-29T00:00:00+00:00');
        $snapshot = (new AssistantRevenueAllocation($catalog))->snapshot([
            'currency' => 'RUB', 'billing_period_days' => 30, 'offer_type' => 'packages', 'monthly_total_minor' => 1000000,
            'amount_due_now_minor' => 1000000, 'quote_version' => 1, 'target_package_slugs' => ['economics-test'], 'current_package_slugs' => [],
            'period_start_at' => $now->toAtomString(), 'period_end_at' => $now->addDays(30)->toAtomString(),
        ], $now, null, null, 1000000);
        self::assertSame('allocated', $snapshot['status']);
        return $snapshot;
    }
}
