<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIUsageRecord;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\OrganizationCommercialAccount;
use App\Services\Credits\AICreditEconomicsMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AICreditEconomicsMonitorTest extends TestCase
{
    public function test_database_report_uses_immutable_paid_grant_time_and_deduplicates_provider_usage(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $account = OrganizationCommercialAccount::query()->where('organization_id', $fixture->organization->id)->firstOrFail();
        $now = CarbonImmutable::now();
        $order = CommercialOrder::query()->create([
            'public_id' => (string) Str::uuid(), 'organization_id' => $fixture->organization->id,
            'commercial_account_id' => $account->id, 'user_id' => $fixture->owner->id, 'status' => 'paid',
            'kind' => 'ai_credits', 'offer_type' => 'packages', 'quote_version' => 1,
            'selected_package_slugs' => [], 'current_package_slugs' => [], 'selected_resource_addons' => [],
            'amount_minor' => 100000, 'amount' => '1000.00', 'currency' => 'RUB',
            'period_start_at' => $now, 'period_end_at' => $now->addDays(30),
            'client_idempotency_key' => 'economics-payment',
        ]);
        $payment = CommercialPayment::query()->create([
            'commercial_order_id' => $order->id, 'provider' => 'yookassa', 'provider_payment_id' => 'economics-provider-payment',
            'provider_status' => 'succeeded', 'amount_minor' => 100000, 'currency' => 'RUB',
            'provider_idempotency_key' => (string) Str::uuid(), 'refunded_amount_minor' => 20000,
            'terminal_at' => null, 'reconciliation_required' => false,
        ]);
        AICreditLot::query()->create(['organization_id' => $fixture->organization->id, 'source' => 'purchase',
            'original_minor' => 100000, 'remaining_minor' => 80000, 'commercial_order_id' => $order->id]);
        CommercialRefund::query()->create([
            'commercial_order_id' => $order->id, 'commercial_payment_id' => $payment->id, 'provider' => 'yookassa',
            'provider_refund_id' => 'economics-refund', 'provider_status' => 'succeeded', 'amount_minor' => 20000,
            'currency' => 'RUB', 'provider_idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'economics-refund'), 'reconciliation_required' => false,
        ]);
        AICreditProviderUsage::query()->create([
            'organization_id' => $fixture->organization->id, 'usage_key' => 'economics-call', 'provider' => 'timeweb',
            'model' => 'luna', 'operation' => 'assistant_chat', 'cost_micro_rub' => 250000, 'is_successful' => false,
            'metadata' => ['cost_available' => true], 'occurred_at' => $now,
        ]);
        AIUsageRecord::query()->create([
            'organization_id' => $fixture->organization->id, 'user_id' => $fixture->owner->id, 'provider' => 'timeweb',
            'model' => 'luna', 'operation' => 'assistant_chat', 'input_tokens' => 10, 'output_tokens' => 1,
            'total_tokens' => 11, 'input_cost_rub' => '0.250000', 'output_cost_rub' => '0.000000', 'total_cost_rub' => '0.250000',
            'currency' => 'RUB', 'metadata' => ['usage_key' => 'economics-call'], 'occurred_at' => $now,
        ]);
        AIUsageRecord::query()->create([
            'organization_id' => $fixture->organization->id, 'user_id' => $fixture->owner->id, 'provider' => 'openai',
            'model' => 'embedding', 'operation' => 'rag_query', 'input_tokens' => 10, 'output_tokens' => 0,
            'total_tokens' => 10, 'input_cost_rub' => '0.010000', 'output_cost_rub' => '0.000000', 'total_cost_rub' => '0.010000',
            'currency' => 'RUB', 'metadata' => ['usage_key' => 'economics-embedding', 'cost_available' => true], 'occurred_at' => $now,
        ]);

        $report = app(AICreditEconomicsMonitor::class)->report($now->subMinute(), $now->addMinute());

        self::assertSame(260000, $report['external_cost_micro_rub']);
        self::assertSame(80000, $report['assistant_revenue_minor']);
        self::assertSame(20000, $report['assistant_refunds_minor']);
        self::assertSame(1, $report['deduplicated_usage_count']);
        self::assertSame('within_30_percent', $report['state']);
        self::assertFalse($report['automatic_paid_approval']);
        self::assertSame('settled_payment_cohort_net_of_current_refunds', $report['revenue_basis']);
        self::assertStringNotContainsString('economics-provider-payment', json_encode($report, JSON_THROW_ON_ERROR));
        $path = tempnam(sys_get_temp_dir(), 'most-economics-test-');
        self::assertIsString($path);
        try {
            $this->artisan('ai-credits:economics-report', ['--date' => $now->utc()->toDateString(), '--days' => 1, '--output' => $path])->assertSuccessful();
            $saved = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(260000, $saved['external_cost_micro_rub']);
            self::assertSame(80000, $saved['assistant_revenue_minor']);
            self::assertStringNotContainsString('economics-call', file_get_contents($path));
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }
}
