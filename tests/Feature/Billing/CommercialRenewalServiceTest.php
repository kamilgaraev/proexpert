<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialContourChange;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRenewalCycle;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Services\Billing\CommercialOfferCalculator;
use App\Services\Billing\CommercialRenewalService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CommercialRenewalServiceTest extends TestCase
{
    private RenewalGatewayFake $gateway;

    private OrganizationCommercialAccount $account;

    private RenewalWebhookProcessorFake $processor;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.yookassa.mode', 'mock');
        $this->schema();
        $this->gateway = new RenewalGatewayFake;
        $this->app->instance(PaymentGatewayInterface::class, $this->gateway);
        $this->processor = new RenewalWebhookProcessorFake;
        $this->app->instance(CommercialWebhookProcessor::class, $this->processor);
        $organization = Organization::query()->create(['name' => 'МОСТ', 'is_active' => true, 'is_verified' => true]);
        $this->account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $organization->id, 'status' => 'active', 'offer_type' => 'packages',
            'quote_version' => 1, 'billing_anchor_at' => '2026-07-01 00:00:00',
            'current_period_start_at' => '2026-07-01 00:00:00', 'current_period_end_at' => '2026-07-31 00:00:00',
            'auto_renew_enabled' => true, 'saved_payment_method_id' => 'saved-method',
            'saved_payment_method_active' => true, 'responsible_user_id' => 1,
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $organization->id, 'commercial_account_id' => $this->account->id,
            'package_slug' => 'machinery', 'status' => 'active', 'access_source' => 'paid_package',
            'price_paid' => 7900, 'current_period_start_at' => '2026-07-01 00:00:00',
            'current_period_end_at' => '2026-07-31 00:00:00',
        ]);
    }

    public function test_due_account_creates_one_fixed_cycle_order_and_attempt_and_reuses_it(): void
    {
        $at = CarbonImmutable::parse('2026-07-31 03:00:00', 'Europe/Moscow');
        $service = app(CommercialRenewalService::class);

        $first = $service->process($at, 50);
        $second = $service->process($at, 50);

        $cycle = CommercialRenewalCycle::query()->sole();
        $order = CommercialOrder::query()->sole();
        $payment = CommercialPayment::query()->sole();
        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $second['created_attempts']);
        $this->assertSame('2026-07-31', $cycle->target_period_start_at->format('Y-m-d'));
        $this->assertSame('2026-08-30', $cycle->target_period_end_at->format('Y-m-d'));
        $this->assertSame('renewal', $order->kind);
        $this->assertSame(['machinery'], $order->selected_package_slugs);
        $this->assertSame(790000, $order->amount_minor);
        $this->assertSame(1, $payment->attempt_number);
        $this->assertSame(1, $this->gateway->creates);
    }

    public function test_first_post_anchor_tick_renews_immutable_paid_and_corporate_period_snapshot(): void
    {
        $at = CarbonImmutable::instance($this->account->current_period_end_at)->addMinute();
        $this->addPackageRows(['planning-schedules'], 'corporate');
        Carbon::setTestNow($at);

        app(CommercialRenewalService::class)->process($at);

        $this->assertDatabaseCount('commercial_orders', 1);
        $order = CommercialOrder::query()->sole();
        $this->assertEqualsCanonicalizing(['machinery', 'planning-schedules'], $order->selected_package_slugs);
        $this->assertEqualsCanonicalizing(['machinery', 'planning-schedules'], $order->current_package_slugs);
        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertSame('grace', $this->account->fresh()->status->value);
        $this->assertSame(
            'grace',
            OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->firstOrFail()->status->value,
        );
        $this->assertSame(
            'grace',
            OrganizationPackageSubscription::query()->where('package_slug', 'planning-schedules')->firstOrFail()->status->value,
        );
        Carbon::setTestNow($at->addMinutes(6));
        $this->assertTrue(OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->firstOrFail()->isActive());
        Carbon::setTestNow();
    }

    public function test_first_post_anchor_tick_renews_immutable_full_suite_period_snapshot(): void
    {
        $slugs = ['machinery', 'estimates-norms', 'finance-contracts', 'planning-schedules', 'projects-processes', 'pto-handover', 'quality-safety', 'sales-contractors', 'supply-warehouse', 'workforce-output'];
        $this->addPackageRows(array_slice($slugs, 1), 'full_suite');
        OrganizationPackageSubscription::query()->update(['access_source' => 'full_suite']);
        $this->account->forceFill(['offer_type' => 'full_suite'])->save();
        $at = CarbonImmutable::instance($this->account->current_period_end_at)->addMinute();
        Carbon::setTestNow($at);

        app(CommercialRenewalService::class)->process($at);

        $this->assertDatabaseCount('commercial_orders', 1);
        $order = CommercialOrder::query()->sole();
        $this->assertSame('full_suite', $order->offer_type->value);
        $this->assertEqualsCanonicalizing($slugs, $order->selected_package_slugs);
        $this->assertEqualsCanonicalizing($slugs, $order->current_package_slugs);
        $this->assertDatabaseCount('commercial_payments', 1);
        Carbon::setTestNow();
    }

    public function test_non_actionable_grace_rows_do_not_starve_due_account_beyond_limit(): void
    {
        $now = CarbonImmutable::parse('2026-07-31 11:01', 'UTC');
        $this->makeAccountNonActionableUntil($this->account, $now->addDay());

        for ($index = 0; $index < 99; $index++) {
            $account = $this->createRenewableAccount('blocked-'.$index.'@example.test', $now->subMinute());
            $this->makeAccountNonActionableUntil($account, $now->addDay());
        }

        $due = $this->createRenewableAccount('due@example.test', $now->subMinute());

        $result = app(CommercialRenewalService::class)->process($now, 100);

        $this->assertSame(1, $result['processed']);
        $this->assertDatabaseHas('commercial_orders', [
            'commercial_account_id' => $due->id,
            'kind' => 'renewal',
        ]);
        $this->assertDatabaseHas('commercial_payments', [
            'commercial_order_id' => CommercialOrder::query()
                ->where('commercial_account_id', $due->id)
                ->where('kind', 'renewal')
                ->value('id'),
            'attempt_number' => 1,
        ]);
    }

    public function test_transport_failure_retries_same_local_intent_and_provider_key(): void
    {
        $this->gateway->failCreates = 2;
        $at = CarbonImmutable::parse('2026-07-31 03:00:00', 'Europe/Moscow');
        $service = app(CommercialRenewalService::class);

        $service->process($at, 50);
        $key = CommercialPayment::query()->sole()->provider_idempotency_key;
        $service->process($at, 50);

        $this->assertSame(1, $this->gateway->creates);
        $service->process($at->addMinutes(5), 50);
        $this->assertSame(2, $this->gateway->creates);
        $service->process($at->addMinutes(6), 50);
        $this->assertSame(2, $this->gateway->creates);
        $service->process($at->addMinutes(10), 50);

        $this->assertSame(1, CommercialPayment::query()->count());
        $this->assertSame($key, CommercialPayment::query()->sole()->provider_idempotency_key);
        $this->assertSame(3, $this->gateway->creates);
    }

    public function test_terminal_canceled_attempt_allows_one_new_attempt_on_following_moscow_day(): void
    {
        $service = app(CommercialRenewalService::class);
        $service->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));
        CommercialPayment::query()->sole()->forceFill(['provider_status' => 'canceled', 'terminal_at' => '2026-07-31 03:01'])->save();
        CommercialRenewalCycle::query()->sole()->forceFill(['status' => 'grace'])->save();

        $service->process(CarbonImmutable::parse('2026-08-01 03:00', 'Europe/Moscow'));
        $service->process(CarbonImmutable::parse('2026-08-01 20:00', 'Europe/Moscow'));

        $this->assertSame(2, CommercialPayment::query()->count());
        $this->assertSame([1, 2], CommercialPayment::query()->orderBy('attempt_number')->pluck('attempt_number')->all());
        $this->assertNotSame(...CommercialPayment::query()->orderBy('attempt_number')->pluck('provider_idempotency_key')->all());
    }

    public function test_minute_ticks_keep_grace_retry_at_three_moscow_time_once_per_date(): void
    {
        $service = app(CommercialRenewalService::class);
        $service->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));
        CommercialPayment::query()->sole()->forceFill([
            'provider_status' => 'canceled',
            'terminal_at' => '2026-07-31 03:01',
        ])->save();
        CommercialRenewalCycle::query()->sole()->forceFill([
            'status' => 'grace',
            'next_attempt_at' => CarbonImmutable::parse('2026-08-01 03:00', 'Europe/Moscow')->utc(),
        ])->save();

        $service->process(CarbonImmutable::parse('2026-08-01 00:01', 'Europe/Moscow'));
        $service->process(CarbonImmutable::parse('2026-08-01 02:59', 'Europe/Moscow'));
        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertSame(1, $this->gateway->creates);

        $service->process(CarbonImmutable::parse('2026-08-01 03:00', 'Europe/Moscow'));
        $service->process(CarbonImmutable::parse('2026-08-01 03:01', 'Europe/Moscow'));

        $this->assertDatabaseCount('commercial_payments', 2);
        $this->assertSame(2, $this->gateway->creates);
    }

    public function test_pending_reconciliation_is_throttled_under_minute_cadence(): void
    {
        $service = app(CommercialRenewalService::class);
        $service->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));

        $service->process(CarbonImmutable::parse('2026-07-31 03:01', 'Europe/Moscow'));
        $service->process(CarbonImmutable::parse('2026-07-31 03:04', 'Europe/Moscow'));
        $this->assertSame(0, $this->gateway->lookups);

        $service->process(CarbonImmutable::parse('2026-07-31 03:05', 'Europe/Moscow'));
        $service->process(CarbonImmutable::parse('2026-07-31 03:06', 'Europe/Moscow'));

        $this->assertSame(1, $this->gateway->lookups);
        $this->assertSame(
            '2026-07-31 03:10',
            CommercialRenewalCycle::query()->sole()->next_attempt_at->setTimezone('Europe/Moscow')->format('Y-m-d H:i'),
        );
    }

    public function test_day_seven_suspends_without_eighth_attempt(): void
    {
        $periodEnd = CarbonImmutable::parse('2026-07-31 14:00', 'Europe/Moscow');
        $this->account->forceFill([
            'current_period_start_at' => $periodEnd->subDays(30)->utc(),
            'current_period_end_at' => $periodEnd->utc(),
            'billing_anchor_at' => $periodEnd->utc(),
        ])->save();
        OrganizationPackageSubscription::query()->update([
            'current_period_start_at' => $periodEnd->subDays(30)->utc(),
            'current_period_end_at' => $periodEnd->utc(),
        ]);
        $service = app(CommercialRenewalService::class);
        $service->process($periodEnd);
        $cycle = CommercialRenewalCycle::query()->sole();
        $this->assertSame('2026-07-31', $cycle->billing_due_date->format('Y-m-d'));
        $this->assertSame('2026-08-07 00:00', $cycle->grace_deadline_at->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));

        for ($day = 1; $day <= 6; $day++) {
            CommercialPayment::query()->latest('attempt_number')->firstOrFail()->forceFill([
                'provider_status' => 'canceled',
                'terminal_at' => $periodEnd->addMinute()->addDays($day - 1),
            ])->save();
            $service->process($periodEnd->addDays($day));
        }
        $service->process(CarbonImmutable::parse('2026-08-07 03:00', 'Europe/Moscow'));

        $this->assertSame('suspended', $this->account->fresh()->status->value);
        $this->assertSame('expired', OrganizationPackageSubscription::query()->sole()->status->value);
        $this->assertSame(7, CommercialPayment::query()->count());
        $this->assertSame('suspended', CommercialRenewalCycle::query()->sole()->status);
    }

    public function test_pending_reconciliation_blocks_new_attempt_and_succeeded_uses_webhook_transition(): void
    {
        $service = app(CommercialRenewalService::class);
        $service->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));
        $order = CommercialOrder::query()->sole();
        $this->gateway->lookup = new PaymentGatewayResult(
            'renewal-1', 'pending', null, 'saved-method', true, ['id' => 'renewal-1'],
            false, true, 790000, 'RUB', ['order_id' => $order->public_id, 'organization_id' => 1],
        );
        $service->process(CarbonImmutable::parse('2026-08-01 03:00', 'Europe/Moscow'));
        $this->assertSame(1, CommercialPayment::query()->count());
        $this->assertSame(0, $this->processor->calls);

        $this->gateway->lookup = new PaymentGatewayResult(
            'renewal-1', 'succeeded', null, 'saved-method', true, ['id' => 'renewal-1'],
            true, true, 790000, 'RUB', ['order_id' => $order->public_id, 'organization_id' => 1],
        );
        $service->process(CarbonImmutable::parse('2026-08-02 03:00', 'Europe/Moscow'));
        $this->assertSame(1, $this->processor->calls);
        $this->assertSame('payment.succeeded', $this->processor->lastEvent);
        $this->assertSame(1, CommercialPayment::query()->count());
    }

    public function test_direct_succeeded_response_is_reconciled_through_webhook_transition(): void
    {
        $result = new PaymentGatewayResult(
            'renewal-direct-success', 'succeeded', null, 'saved-method', true,
            ['id' => 'renewal-direct-success', 'status' => 'succeeded'], true, true, 790000, 'RUB',
            ['order_id' => 'renewal-order', 'organization_id' => 1],
        );
        $this->gateway->createResult = $result;
        $this->gateway->lookup = $result;

        app(CommercialRenewalService::class)->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));
        app(CommercialRenewalService::class)->process(CarbonImmutable::parse('2026-07-31 04:00', 'Europe/Moscow'));

        $this->assertSame(1, $this->processor->calls);
        $this->assertSame('payment.succeeded', $this->processor->lastEvent);
        $this->assertSame(1, CommercialPayment::query()->count());
    }

    public function test_direct_canceled_response_is_reconciled_before_next_daily_attempt(): void
    {
        $result = new PaymentGatewayResult(
            'renewal-direct-canceled', 'canceled', null, 'saved-method', true,
            ['id' => 'renewal-direct-canceled', 'status' => 'canceled'], false, true, 790000, 'RUB',
            ['order_id' => 'renewal-order', 'organization_id' => 1], cancellationReason: 'insufficient_funds',
        );
        $this->gateway->createResult = $result;
        $this->gateway->lookup = $result;

        app(CommercialRenewalService::class)->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));

        $this->assertSame(1, $this->processor->calls);
        $this->assertSame('payment.canceled', $this->processor->lastEvent);
        $this->assertSame(1, CommercialPayment::query()->count());
    }

    public function test_eight_package_contour_renews_as_packages_at_current_exact_sum(): void
    {
        $slugs = ['machinery', 'estimates-norms', 'finance-contracts', 'planning-schedules', 'projects-processes', 'pto-handover', 'quality-safety', 'sales-contractors'];
        $this->addPackageRows(array_slice($slugs, 1));
        $at = CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow');
        $expected = app(CommercialOfferCalculator::class)->preview(
            $slugs, $slugs, false, $at, $this->account->current_period_start_at, $this->account->current_period_end_at,
        );

        app(CommercialRenewalService::class)->process($at);

        $order = CommercialOrder::query()->sole();
        $this->assertSame('packages', $order->offer_type->value);
        $this->assertSame($expected['monthly_total_minor'], $order->amount_minor);
        $this->assertEqualsCanonicalizing($slugs, $order->selected_package_slugs);
    }

    public function test_full_suite_renews_full_catalog_at_current_full_suite_price(): void
    {
        $slugs = ['machinery', 'estimates-norms', 'finance-contracts', 'planning-schedules', 'projects-processes', 'pto-handover', 'quality-safety', 'sales-contractors', 'supply-warehouse', 'workforce-output'];
        $this->addPackageRows(array_slice($slugs, 1), 'full_suite');
        OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->update(['access_source' => 'full_suite']);
        $this->account->forceFill(['offer_type' => 'full_suite'])->save();

        app(CommercialRenewalService::class)->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));

        $order = CommercialOrder::query()->sole();
        $this->assertSame('full_suite', $order->offer_type->value);
        $this->assertSame(7_990_000, $order->amount_minor);
        $this->assertEqualsCanonicalizing($slugs, $order->selected_package_slugs);
    }

    public function test_scheduled_reduced_contour_is_used_once_at_fixed_anchor(): void
    {
        $originalTimezone = config('app.timezone');
        config()->set('app.timezone', 'UTC');
        $anchor = CarbonImmutable::parse('2026-07-31 14:00', 'Europe/Moscow');
        $this->account->forceFill([
            'current_period_start_at' => $anchor->subDays(30)->utc(),
            'current_period_end_at' => $anchor->utc(),
            'billing_anchor_at' => $anchor->utc(),
        ])->save();
        OrganizationPackageSubscription::query()->update([
            'current_period_start_at' => $anchor->subDays(30)->utc(),
            'current_period_end_at' => $anchor->utc(),
        ]);
        $this->addPackageRows(['planning-schedules']);
        OrganizationPackageSubscription::query()->where('package_slug', 'planning-schedules')->update([
            'current_period_start_at' => $anchor->subDays(30)->utc(),
            'current_period_end_at' => $anchor->utc(),
        ]);
        $change = CommercialContourChange::query()->create([
            'public_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'organization_id' => 1,
            'commercial_account_id' => $this->account->id,
            'user_id' => 1,
            'status' => 'scheduled',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'target_package_slugs' => ['machinery'],
            'current_package_slugs' => ['machinery', 'planning-schedules'],
            'apply_at' => $this->account->current_period_end_at,
            'client_idempotency_key' => 'scheduled-reduction-at-anchor-0000001',
        ]);

        $this->gateway->createResult = new PaymentGatewayResult(
            'scheduled-direct-success', 'succeeded', null, 'saved-method', true,
            ['id' => 'scheduled-direct-success', 'status' => 'succeeded'], true, true, 790000, 'RUB',
            ['order_id' => 'scheduled-renewal', 'organization_id' => 1],
        );
        $this->gateway->lookup = $this->gateway->createResult;

        app(CommercialRenewalService::class)->process(
            CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'),
        );
        $this->assertDatabaseCount('commercial_orders', 0);
        $this->assertSame(0, $this->gateway->creates);
        $this->assertSame('scheduled', $change->fresh()->status);
        Carbon::setTestNow($anchor->utc()->subMinute());
        $machinery = OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->firstOrFail();
        $this->assertTrue($machinery->isActive(), json_encode([
            'status' => $machinery->status->value,
            'period_end' => $machinery->current_period_end_at?->toIso8601String(),
            'now' => Carbon::now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        Carbon::setTestNow($anchor->utc()->addSeconds(30));
        app(CommercialRenewalService::class)->process(
            $anchor->addSeconds(30),
        );

        $order = CommercialOrder::query()->sole();
        $this->assertSame(['machinery'], $order->selected_package_slugs);
        $this->assertSame(790000, $order->amount_minor);
        $this->assertSame('applied', $change->fresh()->status);
        $this->assertSame($order->id, $change->fresh()->commercial_order_id);
        $this->assertNotNull($change->fresh()->applied_at);
        $this->assertTrue(
            OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->firstOrFail()->isActive(),
        );
        $this->assertSame(
            'expired',
            OrganizationPackageSubscription::query()
                ->where('package_slug', 'planning-schedules')
                ->firstOrFail()
                ->status
                ->value,
        );

        app(CommercialRenewalService::class)->process(
            $anchor->addHour(),
        );
        $this->assertDatabaseCount('commercial_orders', 1);
        Carbon::setTestNow();
        config()->set('app.timezone', $originalTimezone);
    }

    public function test_scheduled_empty_contour_expires_paid_access_at_anchor_without_payment(): void
    {
        $anchor = CarbonImmutable::parse('2026-07-31 14:00', 'Europe/Moscow');
        $this->account->forceFill([
            'current_period_start_at' => $anchor->subDays(30)->utc(),
            'current_period_end_at' => $anchor->utc(),
            'billing_anchor_at' => $anchor->utc(),
        ])->save();
        OrganizationPackageSubscription::query()->update([
            'current_period_start_at' => $anchor->subDays(30)->utc(),
            'current_period_end_at' => $anchor->utc(),
        ]);
        CommercialContourChange::query()->create([
            'public_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'organization_id' => 1,
            'commercial_account_id' => $this->account->id,
            'user_id' => 1,
            'status' => 'scheduled',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'target_package_slugs' => [],
            'current_package_slugs' => ['machinery'],
            'apply_at' => $this->account->current_period_end_at,
            'client_idempotency_key' => 'scheduled-empty-at-anchor-0000000001',
        ]);

        app(CommercialRenewalService::class)->process(
            CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'),
        );

        $this->assertSame('active', $this->account->fresh()->status->value);
        $this->assertSame('active', OrganizationPackageSubscription::query()->sole()->status->value);
        $this->assertSame('scheduled', CommercialContourChange::query()->sole()->status);
        $this->assertDatabaseCount('commercial_orders', 0);

        app(CommercialRenewalService::class)->process($anchor);

        $this->assertDatabaseCount('commercial_orders', 0);
        $this->assertDatabaseCount('commercial_payments', 0);
        $this->assertSame('free', $this->account->fresh()->status->value);
        $this->assertSame('expired', OrganizationPackageSubscription::query()->sole()->status->value);
        $this->assertSame('applied', CommercialContourChange::query()->sole()->status);
    }

    public function test_auto_renew_disabled_before_due_creates_no_cycle_or_attempt(): void
    {
        $periodEnd = CarbonImmutable::parse('2026-07-31 14:00', 'Europe/Moscow');
        $this->account->forceFill(['current_period_end_at' => $periodEnd->utc(), 'auto_renew_enabled' => false])->save();
        OrganizationPackageSubscription::query()->update(['current_period_end_at' => $periodEnd->utc()]);

        app(CommercialRenewalService::class)->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));

        $this->assertDatabaseCount('commercial_renewal_cycles', 0);
        $this->assertDatabaseCount('commercial_orders', 0);
        $this->assertDatabaseCount('commercial_payments', 0);
        $this->assertSame('active', $this->account->fresh()->status->value);

        app(CommercialRenewalService::class)->process(CarbonImmutable::parse('2026-07-31 15:00', 'Europe/Moscow'));

        $this->assertSame('suspended', $this->account->fresh()->status->value);
        $this->assertNull($this->account->fresh()->grace_started_at);
        $this->assertNull($this->account->fresh()->grace_ends_at);
        $this->assertEquals($periodEnd, OrganizationPackageSubscription::query()->sole()->current_period_end_at);
        $this->assertSame('expired', OrganizationPackageSubscription::query()->sole()->status->value);
    }

    public function test_auto_renew_disabled_during_grace_stops_attempts_but_suspends_at_deadline(): void
    {
        $service = app(CommercialRenewalService::class);
        $service->process(CarbonImmutable::parse('2026-07-31 03:00', 'Europe/Moscow'));
        CommercialPayment::query()->sole()->forceFill(['provider_status' => 'canceled', 'terminal_at' => '2026-07-31 03:01'])->save();
        CommercialRenewalCycle::query()->sole()->forceFill(['status' => 'disabled'])->save();
        $this->account->forceFill(['status' => 'grace', 'auto_renew_enabled' => false, 'grace_ends_at' => '2026-08-06 21:00'])->save();
        OrganizationPackageSubscription::query()->update(['status' => 'grace']);

        $service->process(CarbonImmutable::parse('2026-08-01 03:00', 'Europe/Moscow'));
        $this->assertSame(1, CommercialPayment::query()->count());
        $this->assertSame('grace', OrganizationPackageSubscription::query()->sole()->status->value);
        $service->process(CarbonImmutable::parse('2026-08-08 03:00', 'Europe/Moscow'));
        $this->assertSame('suspended', $this->account->fresh()->status->value);
        $this->assertSame('expired', OrganizationPackageSubscription::query()->sole()->status->value);
        $this->assertSame(1, CommercialRenewalCycle::query()->count());
        $this->assertSame('2026-08-07 00:00', $this->account->fresh()->grace_ends_at->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
    }

    private function addPackageRows(array $slugs, string $source = 'paid_package'): void
    {
        foreach ($slugs as $slug) {
            OrganizationPackageSubscription::query()->create([
                'organization_id' => 1, 'commercial_account_id' => $this->account->id,
                'package_slug' => $slug, 'status' => 'active', 'access_source' => $source,
                'price_paid' => 7900, 'current_period_start_at' => '2026-07-01 00:00:00',
                'current_period_end_at' => '2026-07-31 00:00:00',
            ]);
        }
    }

    private function createRenewableAccount(string $name, CarbonImmutable $periodEnd): OrganizationCommercialAccount
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'is_active' => true,
            'is_verified' => true,
        ]);
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'billing_anchor_at' => $periodEnd,
            'current_period_start_at' => $periodEnd->subDays(30),
            'current_period_end_at' => $periodEnd,
            'auto_renew_enabled' => true,
            'saved_payment_method_id' => 'saved-method-'.$organization->id,
            'saved_payment_method_active' => true,
            'responsible_user_id' => 1,
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 7900,
            'current_period_start_at' => $periodEnd->subDays(30),
            'current_period_end_at' => $periodEnd,
        ]);

        return $account;
    }

    private function makeAccountNonActionableUntil(
        OrganizationCommercialAccount $account,
        CarbonImmutable $nextAttemptAt,
    ): void {
        $account->forceFill(['status' => 'grace'])->save();
        $order = CommercialOrder::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $account->organization_id,
            'commercial_account_id' => $account->id,
            'user_id' => 1,
            'kind' => 'renewal',
            'status' => 'pending_payment',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'selected_package_slugs' => ['machinery'],
            'current_package_slugs' => ['machinery'],
            'amount_minor' => 790000,
            'amount' => '7900.00',
            'currency' => 'RUB',
            'period_start_at' => $account->current_period_end_at,
            'period_end_at' => $account->current_period_end_at->addDays(30),
            'auto_renew_consent' => true,
            'client_idempotency_key' => 'blocked-'.$account->id,
            'server_idempotency_key' => 'blocked-'.$account->id,
        ]);
        CommercialRenewalCycle::query()->create([
            'organization_id' => $account->organization_id,
            'commercial_account_id' => $account->id,
            'commercial_order_id' => $order->id,
            'status' => 'grace',
            'due_at' => $account->current_period_end_at,
            'billing_due_date' => $account->current_period_end_at->toDateString(),
            'target_period_start_at' => $account->current_period_end_at,
            'target_period_end_at' => $account->current_period_end_at->addDays(30),
            'grace_deadline_at' => $account->current_period_end_at->addDays(7),
            'attempt_count' => 1,
            'last_attempt_at' => $account->current_period_end_at,
            'next_attempt_at' => $nextAttemptAt,
        ]);
    }

    private function schema(): void
    {
        foreach (['commercial_contour_changes', 'commercial_payments', 'commercial_renewal_cycles', 'commercial_orders', 'organization_package_subscriptions', 'organization_commercial_accounts', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('organizations', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->boolean('is_active'), $t->boolean('is_verified'), $t->timestamps(), $t->softDeletes()]);
        Schema::create('organization_commercial_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id');
            $t->string('status');
            $t->string('offer_type');
            $t->unsignedInteger('quote_version');
            $t->timestamp('billing_anchor_at')->nullable();
            $t->timestamp('current_period_start_at')->nullable();
            $t->timestamp('current_period_end_at')->nullable();
            $t->boolean('auto_renew_enabled');
            $t->string('saved_payment_method_id')->nullable();
            $t->boolean('saved_payment_method_active');
            $t->foreignId('responsible_user_id')->nullable();
            $t->timestamp('grace_started_at')->nullable();
            $t->timestamp('grace_ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_orders', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id');
            $t->foreignId('organization_id');
            $t->foreignId('commercial_account_id');
            $t->foreignId('user_id');
            $t->string('kind');
            $t->string('status');
            $t->string('offer_type');
            $t->unsignedInteger('quote_version');
            $t->json('selected_package_slugs');
            $t->json('current_package_slugs');
            $t->unsignedBigInteger('amount_minor');
            $t->decimal('amount', 14, 2);
            $t->string('currency');
            $t->timestamp('period_start_at');
            $t->timestamp('period_end_at');
            $t->boolean('auto_renew_consent');
            $t->string('client_idempotency_key');
            $t->string('server_idempotency_key')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_renewal_cycles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id');
            $t->foreignId('commercial_account_id');
            $t->foreignId('commercial_order_id');
            $t->string('status');
            $t->timestamp('due_at');
            $t->date('billing_due_date');
            $t->timestamp('target_period_start_at');
            $t->timestamp('target_period_end_at');
            $t->timestamp('grace_deadline_at');
            $t->unsignedSmallInteger('attempt_count');
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->timestamp('manual_review_at')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('commercial_order_id');
            $t->foreignId('commercial_renewal_cycle_id')->nullable();
            $t->string('role');
            $t->unsignedSmallInteger('attempt_number');
            $t->string('provider');
            $t->string('provider_payment_id')->nullable();
            $t->string('provider_status');
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency');
            $t->uuid('provider_idempotency_key');
            $t->text('confirmation_url')->nullable();
            $t->string('payment_method_id')->nullable();
            $t->boolean('payment_method_saved');
            $t->json('safe_response')->nullable();
            $t->unsignedBigInteger('refunded_amount_minor')->default(0);
            $t->string('terminal_failure_reason')->nullable();
            $t->string('failure_category')->nullable();
            $t->timestamp('attempted_at')->nullable();
            $t->timestamp('terminal_at')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_contour_changes', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id');
            $t->foreignId('organization_id');
            $t->foreignId('commercial_account_id');
            $t->foreignId('user_id');
            $t->string('status');
            $t->string('offer_type');
            $t->unsignedInteger('quote_version');
            $t->json('target_package_slugs');
            $t->json('current_package_slugs');
            $t->timestamp('apply_at');
            $t->string('client_idempotency_key');
            $t->foreignId('commercial_order_id')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
            $t->unique(['commercial_account_id', 'apply_at']);
        });
        Schema::create('organization_package_subscriptions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id');
            $t->foreignId('commercial_account_id');
            $t->string('package_slug');
            $t->string('status');
            $t->string('access_source');
            $t->decimal('price_paid', 12, 2);
            $t->timestamp('current_period_start_at')->nullable();
            $t->timestamp('current_period_end_at')->nullable();
            $t->timestamp('trial_started_at')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('cancel_at')->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->foreignId('source_order_id')->nullable();
            $t->timestamps();
        });
    }
}

final class RenewalGatewayFake implements PaymentGatewayInterface
{
    public int $creates = 0;

    public int $failCreates = 0;

    public int $lookups = 0;

    public ?PaymentGatewayResult $lookup = null;

    public ?PaymentGatewayResult $createResult = null;

    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult
    {
        $this->creates++;
        if ($this->failCreates-- > 0) {
            throw new RuntimeException('timeout');
        }

        return $this->createResult ?? new PaymentGatewayResult('renewal-'.$this->creates, 'pending', null, $payment->paymentMethodId, true, ['id' => 'renewal-'.$this->creates], false, true, $payment->amountMinor, $payment->currency, $payment->metadata);
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        $this->lookups++;

        return $this->lookup ?? throw new RuntimeException('provider unavailable');
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function createRefund(\App\DataTransferObjects\Billing\CreateRefundData $refund): RefundGatewayResult
    {
        throw new RuntimeException('Not used.');
    }
}

final class RenewalWebhookProcessorFake implements CommercialWebhookProcessor
{
    public int $calls = 0;

    public ?string $lastEvent = null;

    public function process(\App\DataTransferObjects\Billing\YooKassaWebhookNotification $notification, string $sourceIp): string
    {
        $this->calls++;
        $this->lastEvent = $notification->event;
        CommercialPayment::query()->where('provider_payment_id', $notification->objectId)->update([
            'provider_status' => $notification->objectState,
            'terminal_at' => now(),
        ]);

        return 'processed';
    }
}
