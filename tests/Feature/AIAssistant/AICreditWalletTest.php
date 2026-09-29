<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\Models\CommercialOrder;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditQuote;
use App\Models\Credits\AICreditReservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditCommercialService;
use App\Services\Credits\AICreditService;
use App\Services\Entitlements\OrganizationEntitlementService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;
use Tests\Support\IsolatedPostgresTestDatabase;

final class AICreditWalletTest extends TestCase
{
    private AICreditService $credits;
    private Organization $organization;
    private User $user;
    private ?string $approvalPath = null;
    private ?string $connectionName = null;
    private ?array $originalConnectionConfiguration = null;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalConnectionConfiguration = config('database.connections.'.$this->connectionName);
        config()->set('database.connections.'.$this->connectionName, IsolatedPostgresTestDatabase::configuration());
        DB::purge($this->connectionName);
        DB::connection($this->connectionName);
        foreach (['ai_credit_provider_usages', 'ai_credit_ledger_entries', 'ai_credit_reservation_allocations', 'ai_credit_reservations', 'ai_credit_quotes', 'ai_credit_lots', 'ai_credit_wallets', 'organization_user', 'organization_resource_allocations', 'commercial_orders', 'organization_commercial_accounts', 'modules', 'users', 'organizations'] as $table) { Schema::dropIfExists($table); }
        Schema::create('organizations', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->boolean('is_active')->default(true); $table->boolean('is_verified')->default(true); $table->timestamps(); $table->softDeletes(); });
        Schema::create('users', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('email'); $table->string('password'); $table->boolean('is_active')->default(true); $table->unsignedBigInteger('current_organization_id')->nullable(); $table->timestamps(); $table->softDeletes(); });
        Schema::create('organization_user', function (Blueprint $table): void { $table->id(); $table->foreignId('organization_id'); $table->foreignId('user_id'); $table->boolean('is_active')->default(true); $table->boolean('is_owner')->default(true); $table->timestamps(); });
        Schema::create('commercial_orders', function (Blueprint $table): void { $table->id(); $table->uuid('public_id'); $table->foreignId('organization_id'); $table->foreignId('commercial_account_id')->nullable(); $table->foreignId('user_id'); $table->string('kind'); $table->string('status'); $table->bigInteger('amount_minor'); $table->string('currency')->default('RUB'); $table->jsonb('selected_resource_addons')->nullable(); $table->timestampTz('period_start_at')->nullable(); $table->timestampTz('period_end_at')->nullable(); $table->timestamps(); });
        Schema::create('organization_commercial_accounts', function (Blueprint $table): void { $table->id(); $table->foreignId('organization_id'); $table->string('status'); $table->timestampTz('current_period_start_at')->nullable(); $table->timestampTz('current_period_end_at')->nullable(); $table->timestamps(); });
        Schema::create('modules', function (Blueprint $table): void { $table->id(); $table->string('slug'); $table->jsonb('limits')->nullable(); $table->timestamps(); });
        Schema::create('organization_resource_allocations', function (Blueprint $table): void { $table->id(); $table->foreignId('organization_id'); $table->string('resource_slug'); $table->string('limit_key'); $table->string('source'); $table->string('status'); $table->decimal('quantity'); $table->timestampTz('period_start_at')->nullable(); $table->timestampTz('period_end_at')->nullable(); $table->jsonb('metadata')->nullable(); $table->timestamps(); });
        (require database_path('migrations/2026_09_29_000006_create_ai_credit_tables.php'))->up();
        $this->organization = Organization::withoutEvents(fn () => Organization::query()->create(['name' => 'Кредиты']));
        $this->user = User::withoutEvents(fn () => User::query()->create(['name' => 'Владелец', 'email' => 'credits@example.test', 'password' => 'password', 'is_active' => true, 'current_organization_id' => $this->organization->id]));
        DB::table('organization_user')->insert(['organization_id' => $this->organization->id, 'user_id' => $this->user->id, 'is_active' => true, 'is_owner' => true]);
        config()->set('app.key', 'wallet-test-readiness-key');
        $this->approvalPath = tempnam(sys_get_temp_dir(), 'most-wallet-readiness-');
        config()->set('ai-assistant-credits.readiness_approval_path', $this->approvalPath);
        $this->writeReadinessApproval();
        config()->set('ai-assistant-credits.enforce', true);
        $this->credits = new AICreditService;
    }

    public function test_successful_calls_round_once_failed_retries_are_internal_and_usage_is_idempotent(): void
    {
        $this->credits->grant($this->organization, 10000, 'purchase', null, 'pack');
        $reservation = $this->reserve();
        $this->credits->recordProviderCost($reservation, 40000, 'timeweb', 'gpt-6-luna', 'completion', ['usage_key' => 'call:1'], true);
        $this->credits->recordProviderCost($reservation, 40000, 'timeweb', 'gpt-6-luna', 'completion', ['usage_key' => 'call:1'], true);
        $this->credits->recordProviderCost($reservation, 40000, 'timeweb', 'gpt-6-luna', 'completion', ['usage_key' => 'call:2'], true);
        $this->credits->recordProviderCost($reservation, 9000000, 'timeweb', 'gpt-6-luna', 'completion', ['usage_key' => 'retry:1'], false);
        $this->assertSame(50, $this->credits->finalize($reservation, 9999));
        $this->assertSame(50, $this->credits->finalize($reservation));
        $this->assertSame(9950, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(3, AICreditProviderUsage::query()->count());
        $this->assertSame(80000, $this->credits->successfulCostMicroRub($reservation));
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
    }

    public function test_charge_boundaries_minimum_and_confirmed_ceiling(): void
    {
        $this->credits->grant($this->organization, 10000, 'purchase', null, 'pack');
        foreach ([[0, 50], [90000, 50], [90001, 100], [99999999, 200]] as [$cost, $expected]) {
            $reservation = $this->reserve();
            $this->credits->recordProviderCost($reservation, $cost, 'timeweb', 'gpt-6-luna', 'completion', [], true);
            $this->assertSame($expected, $this->credits->finalize($reservation));
        }
    }

    public function test_quote_snapshots_prices_limits_and_binds_exact_request_actor_and_organization(): void
    {
        $request = $this->request();
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        $this->assertSame($quote['quote_id'], $this->credits->quote($this->organization, $this->user, $request)['quote_id']);
        $this->assertSame(200, $quote['max_units_minor']);
        config()->set('ai-assistant-credits.pricing.input_micro_rub_per_million', 999999999);
        $this->writeReadinessApproval();
        $this->credits->grant($this->organization, 10000, 'purchase', null, 'pack');
        $reservation = $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request);
        $this->assertSame(14, $this->credits->costMicroRub(1, 0, $reservation));
        $this->assertSame(8192, $this->credits->limits($reservation)['input_tokens']);
        $this->expectException(DomainException::class);
        $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request + ['goal' => 'Подмена']);
    }

    public function test_standalone_greeting_has_a_narrow_approved_ceiling(): void
    {
        $request = [
            'request_id' => (string) Str::uuid(),
            'message' => 'Привет',
            'profile' => 'normal',
            'allow_actions' => false,
            'conversation_id' => null,
            'context' => [
                'source_module' => 'ai-assistant',
                'source_route' => null,
                'entity_refs' => [],
                'period' => null,
                'filters' => [],
                'ui_state' => ['assistant_path' => '/ai-assistant/chat'],
            ],
        ];
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        $this->assertSame(50, $quote['min_units_minor']);
        $this->assertSame(100, $quote['max_units_minor']);
        $this->assertSame('normal', $quote['profile']);

        $this->credits->grant($this->organization, 1000, 'purchase', null, 'greeting-pack');
        $reservation = $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request);
        $this->assertSame(['max_calls' => 1, 'input_tokens' => 8192, 'output_tokens' => 1024], $this->credits->limits($reservation));
        $this->assertSame(100, $reservation->reserved_minor);

        foreach ([
            ['message' => 'Привет, покажи проекты'],
            ['conversation_id' => 123],
            ['allow_actions' => true],
            ['context' => array_replace($request['context'], ['entity_refs' => [['type' => 'project', 'id' => 1]]])],
        ] as $change) {
            $other = array_replace($request, $change, ['request_id' => (string) Str::uuid()]);
            $this->assertSame(800, $this->credits->quote($this->organization, $this->user, $other)['max_units_minor']);
        }
    }

    public function test_included_expires_during_reservation_and_purchased_credits_never_expire(): void
    {
        $this->credits->grant($this->organization, 500, 'subscription', now()->addMinute(), 'period');
        $this->credits->grant($this->organization, 1000, 'purchase', null, 'pack');
        $reservation = $this->reserve();
        $this->assertSame('subscription', $reservation->allocations()->first()->lot->source);
        $this->travel(2)->minutes();
        $this->assertSame(1200, $this->credits->balance($this->organization)['total_minor']);
        $this->credits->cancel($reservation);
        $this->assertSame(1000, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertNull(AICreditLot::query()->where('source', 'purchase')->first()->expires_at);
    }

    public function test_failed_task_and_shadow_do_not_charge_and_duplicate_request_does_not_reserve_twice(): void
    {
        $this->credits->grant($this->organization, 1000, 'purchase', null, 'pack');
        $request = $this->request();
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        $first = $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request);
        $again = $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(200, $this->credits->balance($this->organization)['reserved_minor']);
        $this->credits->recordProviderCost($first, 900001, 'timeweb', 'gpt-6-luna', 'completion', [], true);
        $this->assertSame(0, $this->credits->finalize($first, 0, false));
        config()->set('ai-assistant-credits.enforce', false);
        $shadow = $this->reserve();
        $this->assertSame(0, $shadow->reserved_minor);
        $this->credits->recordProviderCost($shadow, 900001, 'timeweb', 'gpt-6-luna', 'completion', [], true);
        $this->assertSame(0, $this->credits->finalize($shadow));
        $this->assertSame(1000, $this->credits->balance($this->organization)['available_minor']);
    }

    public function test_two_requests_cannot_overreserve_and_expired_quote_is_rejected(): void
    {
        $this->credits->grant($this->organization, 300, 'purchase', null, 'pack');
        $this->reserve();
        try { $this->reserve(); $this->fail('Expected insufficient balance.'); } catch (DomainException) {}
        $this->assertSame(1, AICreditReservation::query()->count());
        $this->assertSame(100, $this->credits->balance($this->organization)['available_minor']);
        $request = $this->request();
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        $this->travel(6)->minutes();
        $this->expectException(DomainException::class);
        $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request);
    }

    public function test_wallet_lock_serializes_competing_grants_and_reservations(): void
    {
        $this->credits->grant($this->organization, 1000, 'purchase', null, 'pack');
        $connectionName = DB::getDefaultConnection();
        config()->set('database.connections.credit_competitor', config('database.connections.'.$connectionName));
        $original = DB::connection($connectionName);
        $original->beginTransaction();
        $original->table('ai_credit_wallets')->where('organization_id', $this->organization->id)->lockForUpdate()->first();
        try {
            DB::setDefaultConnection('credit_competitor');
            DB::statement("SET lock_timeout = '100ms'");
            try { $this->credits->grant($this->organization, 1000, 'purchase', null, 'competing-pack'); $this->fail('Expected wallet lock contention.'); } catch (QueryException $exception) { $this->assertSame('55P03', $exception->errorInfo[0]); }
            try { $this->reserve(); $this->fail('Expected reserve lock contention.'); } catch (QueryException $exception) { $this->assertSame('55P03', $exception->errorInfo[0]); }
        } finally {
            DB::setDefaultConnection($connectionName);
            $original->rollBack();
            DB::purge('credit_competitor');
        }
        $this->credits->grant($this->organization, 1000, 'purchase', null, 'competing-pack');
        $this->credits->grant($this->organization, 1000, 'purchase', null, 'competing-pack');
        $this->assertSame(2000, $this->credits->balance($this->organization)['total_minor']);
        $this->assertSame(2, AICreditLot::query()->count());
    }

    public function test_paid_period_grants_once_and_paid_pack_refund_is_cumulative_and_idempotent(): void
    {
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('hasModuleAccess')->andReturn(true);
        $commercial = new AICreditCommercialService($this->credits, $entitlements);
        $period = $this->order('purchase', 100000, [], now()->startOfMonth(), now()->addMonth()->startOfMonth());
        $commercial->settlePaidOrder($period);
        $commercial->settlePaidOrder($period);
        $this->assertSame(500000, $this->credits->balance($this->organization)['included_minor']);
        $pack = $this->order('ai_credits', 450000, [['slug' => 'ai-credits-5000', 'units_minor' => 500000, 'amount_minor' => 450000]]);
        $commercial->settlePaidOrder($pack);
        $commercial->settlePaidOrder($pack);
        $commercial->refund($pack, 225000);
        $commercial->refund($pack, 225000);
        $this->assertSame(250000, $this->credits->balance($this->organization)['purchased_minor']);
        $commercial->refund($pack, 450000);
        $this->assertSame(0, $this->credits->balance($this->organization)['purchased_minor']);
        $this->assertSame(2, AICreditLedgerEntry::query()->where('type', 'refund')->count());
    }

    public function test_foreign_actor_cannot_use_quote_and_paid_grant_requires_paid_event(): void
    {
        $other = User::withoutEvents(fn () => User::query()->create(['name' => 'Чужой', 'email' => 'foreign@example.test', 'password' => 'password']));
        DB::table('organization_user')->insert(['organization_id' => $this->organization->id, 'user_id' => $other->id, 'is_active' => true, 'is_owner' => false]);
        $request = $this->request();
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        try { $this->credits->begin($this->organization, $other, $quote['quote_id'], $request['request_id'], null, $request); $this->fail('Expected actor isolation.'); } catch (DomainException) {}
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $commercial = new AICreditCommercialService($this->credits, $entitlements);
        $order = $this->order('ai_credits', 100000, [['slug' => 'ai-credits-1000', 'units_minor' => 100000, 'amount_minor' => 100000]]);
        $order->forceFill(['status' => 'pending_payment'])->save();
        $this->expectException(DomainException::class);
        $commercial->settlePaidOrder($order);
    }

    public function test_grant_key_rejects_conflicting_amount_and_provider_cost_cannot_mutate_closed_task(): void
    {
        $this->credits->grant($this->organization, 1000, 'purchase', null, 'immutable-pack');
        try { $this->credits->grant($this->organization, 2000, 'purchase', null, 'immutable-pack'); $this->fail('Expected grant conflict.'); } catch (DomainException) {}
        $reservation = $this->reserve();
        $this->credits->cancel($reservation);
        $this->expectException(DomainException::class);
        $this->credits->recordProviderCost($reservation, 100000, 'timeweb', 'gpt-6-luna', 'completion', [], true);
    }

    public function test_refund_cancels_related_reservation_and_does_not_restore_refunded_units(): void
    {
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $commercial = new AICreditCommercialService($this->credits, $entitlements);
        $pack = $this->order('ai_credits', 100000, [['slug' => 'ai-credits-1000', 'units_minor' => 100000, 'amount_minor' => 100000]]);
        $commercial->settlePaidOrder($pack);
        $reservation = $this->reserve();
        $commercial->refund($pack, 100000);
        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertSame(0, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->credits->cancel($reservation);
        $this->assertSame(0, $this->credits->balance($this->organization)['total_minor']);
    }

    public function test_legacy_conversion_dry_run_carries_only_paid_remainders_once_and_preserves_base_expiry(): void
    {
        $start = now()->startOfMonth();
        $end = now()->addMonth()->startOfMonth();
        $account = \App\Models\OrganizationCommercialAccount::query()->create(['organization_id' => $this->organization->id, 'status' => 'active', 'current_period_start_at' => $start, 'current_period_end_at' => $end]);
        $order = $this->order('purchase', 100000, [], $start, $end);
        $order->forceFill(['commercial_account_id' => $account->id])->save();
        \App\Models\Module::query()->create(['slug' => 'ai-assistant', 'limits' => ['max_ai_requests_per_month' => 5000]]);
        \App\Models\OrganizationResourceAllocation::query()->create(['organization_id' => $this->organization->id, 'resource_slug' => 'extra_ai_requests', 'limit_key' => 'ai_requests_month', 'source' => 'paid_addon', 'status' => 'active', 'quantity' => 1000, 'period_start_at' => $start, 'period_end_at' => $end, 'metadata' => ['commercial_order_id' => $order->public_id]]);
        \App\Models\OrganizationResourceAllocation::query()->create(['organization_id' => $this->organization->id, 'resource_slug' => 'extra_ai_requests', 'limit_key' => 'ai_requests_month', 'source' => 'paid_addon', 'status' => 'active', 'quantity' => 9999, 'period_start_at' => $start, 'period_end_at' => $end, 'metadata' => ['commercial_order_id' => 'unpaid']]);
        $unpaidOrder = $this->order('purchase', 100000, [], $start, $end);
        $unpaidOrder->forceFill(['status' => 'pending_payment'])->save();
        \App\Models\OrganizationResourceAllocation::query()->create(['organization_id' => $this->organization->id, 'resource_slug' => 'extra_ai_requests', 'limit_key' => 'ai_requests_month', 'source' => 'paid_addon', 'status' => 'active', 'quantity' => 9999, 'period_start_at' => $start, 'period_end_at' => $end, 'metadata' => ['commercial_order_id' => $unpaidOrder->public_id]]);
        $usage = Mockery::mock(\App\BusinessModules\Features\AIAssistant\Services\UsageTracker::class);
        $usage->shouldReceive('getMonthlyUsage')->andReturn(4500);
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('hasModuleAccess')->andReturn(true);
        $conversion = new \App\Services\Credits\LegacyAICreditConversionService($this->credits, $usage, $entitlements);
        $report = $conversion->report($this->organization);
        $this->assertTrue($report['dry_run']);
        $this->assertSame(1500, $report['total_units']);
        $this->assertCount(1, $report['paid_addons']);
        $this->assertSame(0, AICreditLot::query()->count());
        $conversion->report($this->organization, true);
        $this->assertSame(0, $conversion->report($this->organization, true)['total_units']);
        $this->assertSame(2, AICreditLot::query()->count());
        $this->assertSame(50000, $this->credits->balance($this->organization)['included_minor']);
        $this->assertSame(100000, $this->credits->balance($this->organization)['purchased_minor']);
        $this->assertSame($end->toAtomString(), AICreditLot::query()->where('source', 'subscription')->first()->expires_at->toAtomString());
    }

    public function test_balance_purchase_capability_uses_billing_permission_and_corporate_guard(): void
    {
        $authorization = Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->with($this->user, 'billing.manage', ['organization_id' => $this->organization->id])->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $authorization);
        $this->assertTrue($this->credits->balance($this->organization, $this->user)['can_purchase']);
        \App\Models\OrganizationCommercialAccount::query()->create(['organization_id' => $this->organization->id, 'status' => 'corporate']);
        $this->assertFalse($this->credits->balance($this->organization, $this->user)['can_purchase']);
    }

    public function test_quote_cannot_cross_organization_even_when_actor_is_member_of_both(): void
    {
        $foreign = Organization::withoutEvents(fn () => Organization::query()->create(['name' => 'Другая организация']));
        DB::table('organization_user')->insert(['organization_id' => $foreign->id, 'user_id' => $this->user->id, 'is_active' => true, 'is_owner' => true]);
        $request = $this->request();
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        $this->credits->grant($foreign, 10000, 'purchase', null, 'pack');
        $this->expectException(DomainException::class);
        $this->credits->begin($foreign, $this->user, $quote['quote_id'], $request['request_id'], null, $request);
    }

    public function test_balance_does_not_grant_automatically_and_disabled_or_expired_paid_period_has_no_base_units(): void
    {
        $this->assertSame(0, $this->credits->balance($this->organization)['total_minor']);
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('hasModuleAccess')->andReturn(false);
        $commercial = new AICreditCommercialService($this->credits, $entitlements);
        $commercial->settlePaidOrder($this->order('purchase', 100000, [], now()->startOfMonth(), now()->addMonth()));
        $commercial->settlePaidOrder($this->order('purchase', 100000, [], now()->subMonth(), now()->subSecond()));
        $this->assertSame(0, $this->credits->balance($this->organization)['included_minor']);
        $this->assertSame(0, AICreditLedgerEntry::query()->where('type', 'grant')->count());
    }

    public function test_ocr_quote_reserves_all_server_pages_and_shadow_budget_retains_implied_charge(): void
    {
        config()->set('ai-assistant-credits.enforce', false);
        $request = ['request_id' => (string) Str::uuid(), 'profile' => 'ocr', 'document_id' => 1, 'checksum' => str_repeat('a', 64), 'page_count' => 7];
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        $this->assertSame(2800, $quote['max_units_minor']);
        $reservation = $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request);
        $this->assertSame(7, $this->credits->limits($reservation)['max_calls']);
        $this->assertSame(0, $reservation->reserved_minor);
        $this->credits->recordProviderCost($reservation, 90001, 'timeweb', 'gpt-6-luna', 'ocr', ['usage_key' => 'page:1'], true);
        $this->assertSame(100, $this->credits->calculatedChargeMinor($reservation));
        $this->assertSame(0, $this->credits->finalize($reservation));
        $this->assertSame(100, $this->credits->calculatedChargeMinor($reservation));
        $this->assertSame(0, $this->credits->balance($this->organization)['total_minor']);
        try { $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, array_replace($request, ['page_count' => 8])); $this->fail('Expected immutable page count.'); } catch (DomainException) {}
    }

    protected function tearDown(): void
    {
        if ($this->approvalPath !== null && is_file($this->approvalPath)) { unlink($this->approvalPath); }
        if ($this->connectionName !== null && $this->originalConnectionConfiguration !== null) {
            DB::purge($this->connectionName);
            config()->set('database.connections.'.$this->connectionName, $this->originalConnectionConfiguration);
            DB::connection($this->connectionName);
        }
        parent::tearDown();
    }

    private function writeReadinessApproval(): void
    {
        \Tests\Support\AssistantCreditReadinessFixture::write($this->approvalPath, (array) config('ai-assistant-credits'), (string) config('app.key'));
    }

    public function test_precise_supplier_tariff_crosses_charge_threshold_without_repricing_existing_quotes(): void
    {
        config()->set('ai-assistant-credits.price_version', 1);
        config()->set('ai-assistant-credits.pricing', ['input_micro_rub_per_million' => 14000000, 'output_micro_rub_per_million' => 68000000]);
        $this->writeReadinessApproval();
        $this->credits->grant($this->organization, 10000, 'purchase', null, 'precise-tariff-pack');
        $old = $this->reserve();
        config()->set('ai-assistant-credits.price_version', 2);
        config()->set('ai-assistant-credits.pricing', ['input_micro_rub_per_million' => 13500000, 'output_micro_rub_per_million' => 67500000]);
        $this->writeReadinessApproval();
        $oldCost = $this->credits->costMicroRub(8192, 1024, $old);
        $this->assertSame(184320, $oldCost);
        $this->credits->recordProviderCost($old, $oldCost, 'timeweb', 'gpt-6-luna', 'completion', [], true);
        $this->assertSame(150, $this->credits->finalize($old));
        $new = $this->reserve();
        $newCost = $this->credits->costMicroRub(8192, 1024, $new);
        $this->assertSame(179712, $newCost);
        $this->credits->recordProviderCost($new, $newCost, 'timeweb', 'gpt-6-luna', 'completion', [], true);
        $this->assertSame(100, $this->credits->finalize($new));
        $this->assertSame(1, AICreditQuote::query()->findOrFail($old->ai_credit_quote_id)->price_version);
        $this->assertSame(2, AICreditQuote::query()->findOrFail($new->ai_credit_quote_id)->price_version);
        $this->assertSame(9750, $this->credits->balance($this->organization)['available_minor']);
    }

    public function test_economics_are_frozen_per_quote_and_next_quote_uses_new_unit_cost(): void
    {
        $this->credits->grant($this->organization, 10000, 'purchase', null, 'pack');
        $first = $this->reserve();
        $this->assertSame(360000, $this->credits->approvedCostMicroRub($first));
        config()->set('ai-assistant-credits.rub_per_unit', 0.36);
        config()->set('ai-assistant-credits.price_version', 3);
        $this->writeReadinessApproval();
        $this->credits->recordProviderCost($first, 90001, 'timeweb', 'gpt-6-luna', 'completion', [], true);
        $this->assertSame(100, $this->credits->finalize($first));
        $next = $this->reserve();
        $this->assertSame(100, $this->credits->approvedMaximumMinor($next));
        $this->assertSame(360000, $this->credits->approvedCostMicroRub($next));
        $this->credits->recordProviderCost($next, 90001, 'timeweb', 'gpt-6-luna', 'completion', [], true);
        $this->assertSame(50, $this->credits->finalize($next));
    }

    public function test_old_quote_missing_economics_keys_uses_fixed_starter_pricing(): void
    {
        $this->credits->grant($this->organization, 10000, 'purchase', null, 'pack');
        $reservation = $this->reserve();
        $quote = AICreditQuote::query()->findOrFail($reservation->ai_credit_quote_id);
        $quote->forceFill(['pricing' => ['input_micro_rub_per_million' => 14000000, 'output_micro_rub_per_million' => 68000000]])->save();
        config()->set('ai-assistant-credits.rub_per_unit', 0.36);
        config()->set('ai-assistant-credits.charge_step_minor', 100);
        $this->credits->recordProviderCost($reservation, 90001, 'timeweb', 'gpt-6-luna', 'completion', [], true);
        $this->assertSame(360000, $this->credits->approvedCostMicroRub($reservation));
        $this->assertSame(100, $this->credits->finalize($reservation));
    }

    public function test_signed_qa_approval_does_not_block_credit_operations(): void
    {
        $this->credits->grant($this->organization, 10000, 'purchase', null, 'pack');
        $request = $this->request();
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        $saved = file_get_contents($this->approvalPath);
        unlink($this->approvalPath);
        $this->assertTrue($this->credits->creditPurchasesEnabled());
        $this->assertNotEmpty($this->credits->quote($this->organization, $this->user, $this->request())['quote_id']);
        config()->set('ai-assistant-credits.rub_per_unit', 0.36);
        $reservation = $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request);
        $this->assertSame($quote['max_units_minor'], $reservation->reserved_minor);
        config()->set('ai-assistant-credits.rub_per_unit', 0.18);
        $approval = json_decode($saved, true, 128, JSON_THROW_ON_ERROR);
        $approval['signature'] = str_repeat('0', 64);
        file_put_contents($this->approvalPath, json_encode($approval, JSON_THROW_ON_ERROR));
        $this->assertTrue($this->credits->creditPurchasesEnabled());
        $this->assertNotEmpty($this->credits->quote($this->organization, $this->user, $this->request())['quote_id']);
    }

    private function request(): array { return ['request_id' => (string) Str::uuid(), 'message' => 'Покажи баланс', 'profile' => 'short']; }
    private function reserve(): AICreditReservation { $request = $this->request(); $quote = $this->credits->quote($this->organization, $this->user, $request); return $this->credits->begin($this->organization, $this->user, $quote['quote_id'], $request['request_id'], null, $request); }
    private function order(string $kind, int $amount, array $packs, ?\DateTimeInterface $start = null, ?\DateTimeInterface $end = null): CommercialOrder { return CommercialOrder::query()->create(['public_id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'user_id' => $this->user->id, 'commercial_account_id' => 1, 'status' => 'paid', 'kind' => $kind, 'amount_minor' => $amount, 'selected_resource_addons' => $packs, 'period_start_at' => $start ?? now(), 'period_end_at' => $end ?? now()]); }
}
