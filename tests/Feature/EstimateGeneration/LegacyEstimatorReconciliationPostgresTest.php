<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\LegacyEstimatorReconciliationData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\ReconcileLegacyEstimatorPaidBalance;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\RestoreLegacyEstimatorPaidBalance;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\DataTransferObjects\Billing\CreateRefundData;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationResourceAllocation;
use App\Models\Project;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Billing\CommercialQuotaService;
use App\Services\Billing\CommercialRefundService;
use App\Services\Credits\AICreditCommercialService;
use App\Services\Credits\AICreditService;
use App\Services\Credits\EstimatorCreditRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class LegacyEstimatorReconciliationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    use EnablesImmutableAuditWriter;

    public function test_confirmed_financial_attestation_grants_once_freezes_the_sale_rate_and_retires_old_quota(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $service = app(ReconcileLegacyEstimatorPaidBalance::class);
        $data = $this->command($actor, $allocation, 600);
        $receipt = $service->confirm((int) $actor->id, $data);
        self::assertSame(600, $receipt['converted_units_minor']);
        self::assertFalse($receipt['idempotent_replay']);
        $replay = $service->confirm((int) $actor->id, $data);
        self::assertSame($receipt['reconciliation_id'], $replay['reconciliation_id']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame(600, (int) DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->value('balance_minor'));
        self::assertSame(1, DB::table('ai_credit_lots')->where('organization_id', $organization->id)->count());
        self::assertSame(1, DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('organization_id', $organization->id)->count());
        self::assertTrue(EstimatorCreditPolicy::enabledForOrganization((int) $organization->id));
        self::assertSame(10, app(CommercialQuotaService::class)->getEffectiveAiEstimateMonthlyLimits([(int) $organization->id])[$organization->id]);
        self::assertSame('10.00', $allocation->fresh()->quantity);
        $audit = DB::table('immutable_audit_events')->where('organization_id', $organization->id)
            ->where('event_type', 'legacy_estimator_balance_reconciled')->first();
        self::assertNotNull($audit);
        self::assertNull($audit->actor_user_id);
        self::assertSame('system_admin', $audit->actor_type);
        config(['ai-assistant-credits.packs.ai-credits-1000.amount_minor' => 120000, 'ai-assistant-credits.price_version' => 3]);
        self::assertTrue($service->confirm((int) $actor->id, $data)['idempotent_replay']);
        $proof = json_decode(DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $receipt['reconciliation_id'])->value('proof'), true);
        self::assertSame(100000, $proof['sale_price']['pack_amount_minor']);
        self::assertSame('finance_admin_attestation', $proof['verification_method']);
    }

    public function test_zero_remainder_has_a_receipt_but_does_not_invent_a_paid_balance(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $service = app(ReconcileLegacyEstimatorPaidBalance::class);
        $data = $this->command($actor, $allocation, 0);
        self::assertSame(0, $service->confirm((int) $actor->id, $data)['converted_units_minor']);
        self::assertSame(0, (int) DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->value('balance_minor'));
        self::assertSame(0, (int) DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->value('amount_minor'));
        self::assertTrue(EstimatorCreditPolicy::enabledForOrganization((int) $organization->id));
        self::assertTrue($service->confirm((int) $actor->id, $data)['idempotent_replay']);
    }

    public function test_an_inactive_financial_actor_or_a_view_only_role_cannot_confirm_a_balance(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $data = $this->command($actor, $allocation, 600);
        foreach (['qa_engineer', 'security_auditor', 'support_operator'] as $role) {
            $actor->forceFill(['role' => $role])->save();
            try {
                app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $data);
                self::fail('A view-only actor granted money.');
            } catch (\DomainException $error) {
                self::assertSame('legacy_estimator_financial_permission_required', $error->getMessage());
            }
        }
        $actor->forceFill(['role' => 'super_admin', 'is_active' => false])->save();
        $this->expectException(\DomainException::class);
        app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $data);
    }

    private function command(SystemAdmin $actor, OrganizationResourceAllocation $allocation, int $remaining, string $pack = 'ai-credits-1000'): LegacyEstimatorReconciliationData
    {
        $preview = app(ReconcileLegacyEstimatorPaidBalance::class)->preview((int) $actor->id, (int) $allocation->organization_id, (int) $allocation->id, $pack);

        return new LegacyEstimatorReconciliationData((int) $allocation->organization_id, (int) $allocation->id, (string) Str::uuid(),
            $preview['allocation_hash'], $preview['purchase_hash'], $preview['sale_price_hash'], $remaining, $pack,
            'Finance reconciliation fixture RECON-001', hash('sha256', 'fixture signed reconciliation statement'),
            'Financial operator confirms the remaining paid value; mixed quota totals are not used to infer it.');
    }

    public function test_changed_replay_and_changed_sale_price_do_not_create_another_credit(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $service = app(ReconcileLegacyEstimatorPaidBalance::class);
        $data = $this->command($actor, $allocation, 600);
        $service->confirm((int) $actor->id, $data);
        $changed = new LegacyEstimatorReconciliationData(...array_replace(get_object_vars($data), ['remainingPaidAmountMinor' => 700]));
        try {
            $service->confirm((int) $actor->id, $changed);
            self::fail('Changed monetary replay granted twice.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_reconciliation_request_changed', $error->getMessage());
        }
        self::assertSame(600, (int) DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->value('balance_minor'));
        self::assertSame(1, DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->count());
        [$actor, $organization, $allocation] = $this->fixture();
        $data = $this->command($actor, $allocation, 600);
        config(['ai-assistant-credits.packs.ai-credits-1000.amount_minor' => 120000]);
        try {
            $service->confirm((int) $actor->id, $data);
            self::fail('A changed sale price bypassed the accepted snapshot.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_sale_price_changed', $error->getMessage());
        }
        self::assertSame(0, DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('organization_id', $organization->id)->count());
    }

    public function test_value_above_the_paid_ceiling_and_a_refunded_payment_remain_on_the_old_path(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $service = app(ReconcileLegacyEstimatorPaidBalance::class);
        $data = $this->command($actor, $allocation, 1001);
        try {
            $service->confirm((int) $actor->id, $data);
            self::fail('A balance larger than the paid purchase was granted.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_remaining_money_exceeds_purchase', $error->getMessage());
        }
        $data = $this->command($actor, $allocation, 600);
        $order = CommercialOrder::query()->where('organization_id', $organization->id)->firstOrFail();
        CommercialPayment::query()->where('commercial_order_id', $order->id)->update(['refunded_amount_minor' => 1]);
        try {
            $service->confirm((int) $actor->id, $data);
            self::fail('Unknown refund attribution was silently converted.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_purchase_requires_reconciliation', $error->getMessage());
        }
        self::assertSame(0, DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('ai_credit_lots')->where('organization_id', $organization->id)->count());
        self::assertFalse(EstimatorCreditPolicy::enabledForOrganization((int) $organization->id));
    }

    public function test_unused_conversion_is_restored_by_a_compensating_entry_without_rewriting_the_proof(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $data = $this->command($actor, $allocation, 600);
        $receipt = app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $data);
        $before = DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $receipt['reconciliation_id'])->first();
        $requestId = (string) Str::uuid();
        $service = app(RestoreLegacyEstimatorPaidBalance::class);
        $result = $service->handle((int) $actor->id, (int) $organization->id, $receipt['reconciliation_id'], $requestId, 'Fixture correction to financial reconciliation.');
        self::assertSame(600, $result['reversed_units_minor']);
        self::assertFalse($result['idempotent_replay']);
        self::assertTrue($service->handle((int) $actor->id, (int) $organization->id, $receipt['reconciliation_id'], $requestId, 'Fixture correction to financial reconciliation.')['idempotent_replay']);
        self::assertSame(0, (int) DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->value('balance_minor'));
        self::assertSame(2, DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->count());
        self::assertFalse(EstimatorCreditPolicy::enabledForOrganization((int) $organization->id));
        self::assertSame(20, app(CommercialQuotaService::class)->getEffectiveAiEstimateMonthlyLimits([(int) $organization->id])[$organization->id]);
        self::assertSame($before->proof_hash, DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $receipt['reconciliation_id'])->value('proof_hash'));
        self::assertSame('10.00', $allocation->fresh()->quantity);
    }

    private function fixture(): array
    {
        config(['cache.default' => 'array', 'estimate-generation.credits.enabled' => true,
            'estimate-generation.credits.legacy_reconciliation_enabled' => true,
            'commercial_limits.ai_estimates.included_monthly' => 10, 'legal_archive.audit_writer_secret' => 'fixture-only-legacy-proof-writer-ABC123xyz789',
            'ai-assistant-credits.price_version' => 2, 'ai-assistant-credits.unit_minor' => 100,
            'ai-assistant-credits.packs.ai-credits-1000' => ['units_minor' => 100000, 'amount_minor' => 100000]]);
        $this->enableImmutableAuditWriter();
        $actor = SystemAdmin::query()->create(['name' => 'Finance fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'fixture-password', 'role' => 'super_admin', 'is_active' => true]);
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $account = OrganizationCommercialAccount::query()->create(['organization_id' => $organization->id,
            'responsible_user_id' => $owner->id, 'status' => 'free', 'offer_type' => 'packages', 'quote_version' => 1, 'auto_renew_enabled' => false]);
        $start = now()->subDay();
        $end = now()->addDay();
        $order = CommercialOrder::query()->create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id,
            'commercial_account_id' => $account->id, 'user_id' => $owner->id, 'status' => 'paid', 'kind' => 'purchase',
            'offer_type' => 'packages', 'quote_version' => 1, 'amount_minor' => 1000, 'amount' => '10.00', 'currency' => 'RUB',
            'selected_package_slugs' => [], 'current_package_slugs' => [],
            'selected_resource_addons' => [['slug' => 'extra_ai_estimates', 'limit_key' => 'ai_estimates_month', 'quantity' => 10,
                'amount_minor' => 1000, 'currency' => 'RUB', 'status' => 'ok']], 'period_start_at' => $start, 'period_end_at' => $end,
            'client_idempotency_key' => (string) Str::uuid(), 'server_idempotency_key' => (string) Str::uuid(), 'auto_renew_consent' => false]);
        CommercialPayment::query()->create(['commercial_order_id' => $order->id, 'provider' => 'yookassa',
            'provider_payment_id' => (string) Str::uuid(), 'provider_status' => 'succeeded', 'amount_minor' => 1000, 'currency' => 'RUB',
            'provider_idempotency_key' => (string) Str::uuid(), 'role' => 'initial', 'attempt_number' => 1,
            'refunded_amount_minor' => 0, 'reconciliation_required' => false]);
        $allocation = OrganizationResourceAllocation::query()->create(['organization_id' => $organization->id,
            'commercial_account_id' => $account->id, 'resource_slug' => 'extra_ai_estimates', 'limit_key' => 'ai_estimates_month',
            'quantity' => 10, 'source' => 'paid_addon', 'status' => 'active', 'period_start_at' => $start, 'period_end_at' => $end,
            'metadata' => ['commercial_order_id' => $order->public_id, 'amount_minor' => 1000, 'currency' => 'RUB']]);

        return [$actor, $organization, $allocation];
    }

    public function test_inflight_legacy_reserve_blocks_conversion_and_stale_legacy_start_cannot_bypass_the_new_quote(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->for($organization)->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $owner->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserveSession((string) $organization->id, (string) $session->id);
        $data = $this->command($actor, $allocation, 600);
        $service = app(ReconcileLegacyEstimatorPaidBalance::class);
        try {
            $service->confirm((int) $actor->id, $data);
            self::fail('A live legacy reserve was converted underneath its running operation.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_inflight_quota_requires_completion', $error->getMessage());
        }
        self::assertSame(0, DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('organization_id', $organization->id)->count());
        DB::table('estimate_generation_ai_estimate_quota_reservations')->where('session_id', $session->id)->update(['status' => 'released', 'released_at' => now()]);
        $service->confirm((int) $actor->id, $data);
        try {
            $quota->reserveSession((string) $organization->id, (string) $session->id);
            self::fail('A stale legacy start bypassed the new wallet quote after conversion.');
        } catch (\DomainException $error) {
            self::assertSame('estimator_credit_quote_required', $error->getMessage());
        }
        self::assertSame('released', DB::table('estimate_generation_ai_estimate_quota_reservations')->where('session_id', $session->id)->value('status'));
    }

    public function test_a_consumed_or_expired_conversion_cannot_be_restored_into_reusable_legacy_quota(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $receipt = app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $this->command($actor, $allocation, 600));
        DB::table('ai_credit_lots')->where('organization_id', $organization->id)->update(['remaining_minor' => 550]);
        DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->update(['balance_minor' => 550]);
        try {
            app(RestoreLegacyEstimatorPaidBalance::class)->handle((int) $actor->id, (int) $organization->id, $receipt['reconciliation_id'], (string) Str::uuid(), 'Fixture consumed conversion.');
            self::fail('Spent conversion became reusable paid launches.');
        } catch (\DomainException $error) {
            self::assertSame('AI credit conversion is already used or reserved.', $error->getMessage());
        }
        self::assertSame(550, (int) DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->value('balance_minor'));
        self::assertSame(1, DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->count());
        $allocation->forceFill(['period_end_at' => now()->subSecond()])->save();
        try {
            app(RestoreLegacyEstimatorPaidBalance::class)->handle((int) $actor->id, (int) $organization->id, $receipt['reconciliation_id'], (string) Str::uuid(), 'Fixture expired legacy period.');
            self::fail('An expired old purchase replaced usable credits.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_restore_allocation_changed_or_expired', $error->getMessage());
        }
    }

    public function test_disabled_migration_blocks_new_grants_and_keeps_an_existing_receipt_readable(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $service = app(ReconcileLegacyEstimatorPaidBalance::class);
        $data = $this->command($actor, $allocation, 600);
        config(['estimate-generation.credits.legacy_reconciliation_enabled' => false]);
        try {
            $service->confirm((int) $actor->id, $data);
            self::fail('Disabled reconciliation granted money.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_reconciliation_disabled', $error->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_lots')->where('organization_id', $organization->id)->count());
        config(['estimate-generation.credits.legacy_reconciliation_enabled' => true]);
        $service->confirm((int) $actor->id, $data);
        config(['estimate-generation.credits.legacy_reconciliation_enabled' => false]);
        self::assertTrue($service->confirm((int) $actor->id, $data)['idempotent_replay']);
    }

    public function test_a_converted_purchase_cannot_be_refunded_even_after_the_legacy_allocation_expires(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $this->command($actor, $allocation, 600));
        $allocation->forceFill(['period_end_at' => now()->subSecond()])->save();
        $order = CommercialOrder::query()->where('organization_id', $organization->id)->firstOrFail();
        $gateway = \Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldNotReceive('createRefund');
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        config(['services.yookassa.mode' => 'yookassa_test', 'services.yookassa.test_organization_ids' => []]);
        try {
            app(CommercialRefundService::class)->create($order->public_id, 100, 'RUB', 'Fixture financial refund.', (string) Str::uuid());
            self::fail('Refund was sent while the converted credits remained spendable.');
        } catch (\DomainException $error) {
            self::assertSame(trans_message('billing.refund.estimator_conversion_requires_reconciliation'), $error->getMessage());
        }
        self::assertSame(0, CommercialRefund::query()->where('commercial_order_id', $order->id)->count());
        self::assertSame(600, (int) DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->value('balance_minor'));
    }

    public function test_an_unbound_refund_replay_cannot_skip_the_conversion_guard(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $this->command($actor, $allocation, 600));
        $order = CommercialOrder::query()->where('organization_id', $organization->id)->firstOrFail();
        $payment = CommercialPayment::query()->where('commercial_order_id', $order->id)->firstOrFail();
        $key = (string) Str::uuid();
        $reason = 'Fixture previously unbound refund.';
        CommercialRefund::query()->create(['commercial_order_id' => $order->id, 'commercial_payment_id' => $payment->id,
            'provider' => 'yookassa', 'provider_idempotency_key' => $key,
            'request_fingerprint' => hash('sha256', implode('|', [$order->public_id, 100, 'RUB', $reason])),
            'provider_status' => 'created', 'amount_minor' => 100, 'currency' => 'RUB', 'reconciliation_required' => true]);
        $gateway = \Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldNotReceive('createRefund');
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        config(['services.yookassa.mode' => 'yookassa_test', 'services.yookassa.test_organization_ids' => []]);
        $this->expectExceptionMessage(trans_message('billing.refund.estimator_conversion_requires_reconciliation'));
        app(CommercialRefundService::class)->create($order->public_id, 100, 'RUB', $reason, $key);
    }

    public function test_a_fully_restored_conversion_allows_the_original_purchase_refund(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $receipt = app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $this->command($actor, $allocation, 600));
        app(RestoreLegacyEstimatorPaidBalance::class)->handle((int) $actor->id, (int) $organization->id,
            $receipt['reconciliation_id'], (string) Str::uuid(), 'Fixture restore before refund.');
        $order = CommercialOrder::query()->where('organization_id', $organization->id)->firstOrFail();
        $gateway = \Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('createRefund')->once()->andReturnUsing(static fn (CreateRefundData $request): RefundGatewayResult => new RefundGatewayResult('fixture-refund-'.Str::uuid(), $request->paymentId, 'pending', $request->amountMinor,
            $request->currency, ['fixture' => true], $request->metadata));
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        config(['services.yookassa.mode' => 'yookassa_test', 'services.yookassa.test_organization_ids' => []]);
        $refund = app(CommercialRefundService::class)->create($order->public_id, 100, 'RUB', 'Fixture refund after restore.', (string) Str::uuid());
        self::assertSame('pending', $refund->provider_status);
        self::assertNotNull($refund->provider_refund_id);
        self::assertSame(0, (int) DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->value('balance_minor'));
    }

    public function test_a_financial_statement_cannot_be_rewritten_or_used_for_another_organization(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $receipt = app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $this->command($actor, $allocation, 600));
        $other = Organization::factory()->create();
        try {
            app(RestoreLegacyEstimatorPaidBalance::class)->handle((int) $actor->id, (int) $other->id,
                $receipt['reconciliation_id'], (string) Str::uuid(), 'Fixture foreign organization.');
            self::fail('Another organization used the financial statement.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_restore_scope_invalid', $error->getMessage());
        }
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(static function () use ($receipt, $operation): void {
                    $query = DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $receipt['reconciliation_id']);
                    $operation === 'update' ? $query->update(['remaining_paid_amount_minor' => 601]) : $query->delete();
                });
                self::fail('Immutable financial proof was changed.');
            } catch (\Illuminate\Database\QueryException $error) {
                self::assertStringContainsString('estimate_generation.legacy_proof_immutable', $error->getMessage());
            }
        }
        self::assertSame(600, (int) DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $receipt['reconciliation_id'])->value('remaining_paid_amount_minor'));
    }

    public function test_a_reserved_conversion_can_be_restored_only_after_its_reservation_is_cancelled(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $receipt = app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $this->command($actor, $allocation, 600));
        $owner = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $owner->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($organization)->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $owner->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
        $inputHash = hash('sha256', 'reserved conversion fixture');
        $request = new EstimatorCreditRequest((int) $organization->id, (int) $project->id, (int) $session->id, (int) $owner->id,
            (string) Str::uuid(), 'generation', 0, $inputHash, 'sha256:'.$inputHash, hash('sha256', 'manifest'), hash('sha256', 'plan'),
            1, 1, app(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class)
                ->resolve('estimate_composition', 'timeweb', 'openai/gpt-6-luna', new \DateTimeImmutable)->toArray());
        $credits = app(AICreditService::class);
        $quote = $credits->quoteEstimator($organization, $owner, $request);
        $reservation = $credits->beginEstimator($organization, $owner, $quote['quote_id'], $request);
        $restore = app(RestoreLegacyEstimatorPaidBalance::class);
        try {
            $restore->handle((int) $actor->id, (int) $organization->id, $receipt['reconciliation_id'], (string) Str::uuid(), 'Fixture reserved conversion.');
            self::fail('Reserved credits were reversed underneath another operation.');
        } catch (\DomainException $error) {
            self::assertSame('AI credit conversion is already used or reserved.', $error->getMessage());
        }
        self::assertSame('reserved', $reservation->fresh()->status);
        $credits->cancel($reservation);
        self::assertSame(600, $restore->handle((int) $actor->id, (int) $organization->id,
            $receipt['reconciliation_id'], (string) Str::uuid(), 'Fixture reservation cancelled.')['reversed_units_minor']);
        self::assertSame(0, (int) DB::table('ai_credit_wallets')->where('organization_id', $organization->id)->value('balance_minor'));
    }

    public function test_the_existing_grant_api_still_rejects_a_generic_zero_credit_grant(): void
    {
        [, $organization] = $this->fixture();
        try {
            app(AICreditService::class)->grant($organization, 0, 'conversion', null, 'fixture:generic-zero');
            self::fail('A zero financial grant bypassed the dedicated reconciliation receipt.');
        } catch (\DomainException $error) {
            self::assertSame('Invalid AI credit grant.', $error->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_lots')->where('organization_id', $organization->id)->count());
    }

    public function test_an_external_refund_holds_the_attested_remainder_without_inventing_its_refund_share(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        $receipt = app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $this->command($actor, $allocation, 600));
        $order = CommercialOrder::query()->where('organization_id', $organization->id)->firstOrFail();
        $proofHash = DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $receipt['reconciliation_id'])->value('proof_hash');
        CommercialPayment::query()->where('commercial_order_id', $order->id)->update(['refunded_amount_minor' => 100]);
        $commercial = app(AICreditCommercialService::class);
        $commercial->refund($order, 100);
        $commercial->refund($order, 100);
        $credits = app(AICreditService::class);
        $balance = $credits->balance($organization);
        self::assertSame(600, $balance['total_minor']);
        self::assertSame(600, $balance['reconciliation_held_minor']);
        self::assertSame(0, $balance['available_minor']);
        self::assertSame(600, (int) DB::table('ai_credit_lots')->where('organization_id', $organization->id)->value('remaining_minor'));
        self::assertSame(1, DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->where('type', 'reconciliation_hold')->count());
        self::assertSame(0, (int) DB::table('ai_credit_ledger_entries')->where('organization_id', $organization->id)->where('type', 'reconciliation_hold')->value('amount_minor'));
        self::assertSame($proofHash, DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('id', $receipt['reconciliation_id'])->value('proof_hash'));
        try {
            app(RestoreLegacyEstimatorPaidBalance::class)->handle((int) $actor->id, (int) $organization->id,
                $receipt['reconciliation_id'], (string) Str::uuid(), 'Fixture restore after external refund.');
            self::fail('Returned money became reusable legacy paid launches.');
        } catch (\DomainException $error) {
            self::assertSame('legacy_estimator_purchase_requires_reconciliation', $error->getMessage());
        }
        [$owner, $request] = $this->creditRequest($organization);
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $snapshot = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot::class)
            ->handle($session, ['estimate_generation.view'])->toArray();
        self::assertSame(600, $snapshot['credit_policy']['reconciliation_held_units_minor']);
        self::assertSame(0, $snapshot['credit_policy']['available_units_minor']);
        $quote = $credits->quoteEstimator($organization, $owner, $request);
        try {
            $credits->beginEstimator($organization, $owner, $quote['quote_id'], $request);
            self::fail('Held converted credits funded a new model operation.');
        } catch (\DomainException $error) {
            self::assertSame('Insufficient AI credits.', $error->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        // A different verified purchase remains usable; the hold applies to the conversion lot alone.
        $credits->grant($organization, 200, 'purchase', null, 'fixture:unrelated-purchase');
        $reservation = $credits->beginEstimator($organization, $owner, $quote['quote_id'], $request);
        $conversionId = DB::table('ai_credit_lots')->where('organization_id', $organization->id)->where('source', 'conversion')->value('id');
        self::assertSame(0, DB::table('ai_credit_reservation_allocations')->where('ai_credit_reservation_id', $reservation->id)
            ->where('ai_credit_lot_id', $conversionId)->count());
        $credits->cancel($reservation);
        self::assertSame(200, $credits->balance($organization)['available_minor']);
    }

    public function test_an_external_refund_cancels_an_active_conversion_reservation_before_holding_its_remainder(): void
    {
        [$actor, $organization, $allocation] = $this->fixture();
        app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, $this->command($actor, $allocation, 600));
        [$owner, $request] = $this->creditRequest($organization);
        $credits = app(AICreditService::class);
        $quote = $credits->quoteEstimator($organization, $owner, $request);
        $reservation = $credits->beginEstimator($organization, $owner, $quote['quote_id'], $request);
        self::assertGreaterThan(0, (int) $reservation->reserved_minor);
        $order = CommercialOrder::query()->where('organization_id', $organization->id)->firstOrFail();
        app(AICreditCommercialService::class)->refund($order, 100);
        self::assertSame('cancelled', $reservation->fresh()->status);
        $balance = $credits->balance($organization);
        self::assertSame(600, $balance['total_minor']);
        self::assertSame(600, $balance['reconciliation_held_minor']);
        self::assertSame(0, $balance['reserved_minor']);
        self::assertSame(0, $balance['available_minor']);
        self::assertSame(0, $credits->finalize($reservation));
    }

    private function creditRequest(Organization $organization): array
    {
        $owner = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $owner->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($organization)->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $owner->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
        $hash = hash('sha256', 'external refund credit fixture');
        $request = new EstimatorCreditRequest((int) $organization->id, (int) $project->id, (int) $session->id, (int) $owner->id,
            (string) Str::uuid(), 'generation', 0, $hash, 'sha256:'.$hash, $hash, $hash, 1, 1,
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class)
                ->resolve('estimate_composition', 'timeweb', 'openai/gpt-6-luna', new \DateTimeImmutable)->toArray());

        return [$owner, $request];
    }
}
