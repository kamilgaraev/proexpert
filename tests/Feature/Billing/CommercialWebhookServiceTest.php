<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Models\CommercialRenewalCycle;
use App\Models\CommercialWebhookEvent;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationPackageTrialUsage;
use App\Models\User;
use App\Services\Billing\CommercialWebhookService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CommercialWebhookServiceTest extends TestCase
{
    private AuthoritativeGatewayFake $gateway;

    private Organization $organization;

    private User $user;

    private OrganizationCommercialAccount $account;

    private CommercialOrder $order;

    private CommercialPayment $payment;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        config()->set('services.yookassa.mode', 'test');
        $this->gateway = new AuthoritativeGatewayFake;
        $this->app->instance(PaymentGatewayInterface::class, $this->gateway);

        $this->organization = Organization::withoutEvents(fn (): Organization => Organization::create([
            'name' => 'Webhook organization', 'is_active' => true, 'is_verified' => true,
        ]));
        $this->user = User::withoutEvents(fn (): User => User::create([
            'name' => 'Webhook owner', 'email' => 'webhook@example.test', 'password' => 'password', 'is_active' => true,
        ]));
        $this->account = OrganizationCommercialAccount::create([
            'organization_id' => $this->organization->id, 'status' => 'free', 'offer_type' => 'packages',
            'quote_version' => 1, 'auto_renew_enabled' => false,
        ]);
        $this->order = CommercialOrder::create([
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'status' => 'pending_payment', 'offer_type' => 'packages', 'quote_version' => 1,
            'selected_package_slugs' => ['machinery'], 'current_package_slugs' => [],
            'amount_minor' => 790000, 'amount' => '7900.00', 'currency' => 'RUB',
            'period_start_at' => '2026-07-14 10:00:00', 'period_end_at' => '2026-08-14 10:00:00',
            'auto_renew_consent' => true, 'client_idempotency_key' => 'checkout-key',
        ]);
        $this->payment = CommercialPayment::create([
            'commercial_order_id' => $this->order->id, 'provider' => 'yookassa',
            'provider_payment_id' => 'payment-id', 'provider_status' => 'pending',
            'amount_minor' => 790000, 'currency' => 'RUB',
            'provider_idempotency_key' => '22222222-2222-4222-8222-222222222222',
            'payment_method_saved' => false, 'refunded_amount_minor' => 0,
        ]);
    }

    public function test_verified_success_activates_trial_once_and_saves_method_only_with_consent(): void
    {
        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id, 'commercial_account_id' => $this->account->id,
            'package_slug' => 'machinery', 'status' => 'trialing', 'access_source' => 'trial',
            'price_paid' => 0, 'trial_started_at' => now(), 'trial_ends_at' => now()->addDays(3),
        ]);
        $trialUsage = OrganizationPackageTrialUsage::create([
            'organization_id' => $this->organization->id,
            'package_slug' => 'machinery',
            'started_at' => now(),
            'ends_at' => now()->addDays(3),
        ]);
        $this->gateway->payment = $this->paymentResult(saved: true);

        $service = app(CommercialWebhookService::class);
        $this->assertSame('processed', $service->process($this->notification('payment.succeeded', 'payment-id', 'succeeded'), '185.71.76.1'));
        $this->assertSame('duplicate', $service->process($this->notification('payment.succeeded', 'payment-id', 'succeeded'), '185.71.76.1'));

        $order = $this->order->fresh();
        $payment = $this->payment->fresh();
        $account = $this->account->fresh();
        $access = OrganizationPackageSubscription::query()->sole();
        $this->assertSame('paid', $order->status->value);
        $this->assertSame('succeeded', $payment->provider_status);
        $this->assertTrue($payment->payment_method_saved);
        $this->assertSame('method-id', $payment->payment_method_id);
        $this->assertSame('active', $account->status->value);
        $this->assertEquals($order->period_end_at, $account->billing_anchor_at);
        $this->assertTrue($account->auto_renew_enabled);
        $this->assertSame('active', $access->status->value);
        $this->assertSame('paid_package', $access->access_source->value);
        $this->assertSame($order->id, $access->source_order_id);
        $this->assertNull($access->trial_started_at);
        $this->assertNull($access->trial_ends_at);
        $this->assertTrue(OrganizationPackageTrialUsage::query()->whereKey($trialUsage->id)->exists());
        $this->assertSame(1, OrganizationPackageTrialUsage::query()->count());
        $this->assertSame(1, Notification::query()->count());
        $this->assertSame(['in_app'], Notification::query()->sole()->channels);
        $this->assertSame(1, CommercialWebhookEvent::query()->count());
    }

    public function test_late_purchase_success_during_grace_requires_manual_reconciliation_without_activation(): void
    {
        $this->account->forceFill([
            'status' => 'grace',
            'current_period_start_at' => now()->subDays(30),
            'current_period_end_at' => now()->subHour(),
            'grace_started_at' => now()->subHour(),
            'grace_ends_at' => now()->addDays(7),
        ])->save();
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $this->account->id,
            'package_slug' => 'planning-schedules',
            'status' => 'grace',
            'access_source' => 'paid_package',
            'price_paid' => 7900,
            'current_period_start_at' => now()->subDays(30),
            'current_period_end_at' => now()->subHour(),
        ]);
        $this->gateway->payment = $this->paymentResult(saved: true);

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertSame('manual_review', $result);
        $this->assertSame('pending_payment', $this->order->fresh()->status->value);
        $this->assertSame('succeeded', $this->payment->fresh()->provider_status);
        $this->assertNull($this->payment->fresh()->confirmation_url);
        $this->assertNotNull($this->payment->fresh()->terminal_at);
        $this->assertSame('grace', $this->account->fresh()->status->value);
        $this->assertSame(
            ['planning-schedules'],
            OrganizationPackageSubscription::query()->pluck('package_slug')->all(),
        );
        $this->assertSame('grace', OrganizationPackageSubscription::query()->sole()->status->value);
        $this->assertSame('manual_review', CommercialWebhookEvent::query()->sole()->processing_result);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_authoritative_mismatch_is_durable_no_op(): void
    {
        $this->gateway->payment = $this->paymentResult(metadata: ['order_id' => 'foreign', 'organization_id' => $this->organization->id]);

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertSame('mismatch', $result);
        $this->assertSame('pending_payment', $this->order->fresh()->status->value);
        $this->assertSame(0, OrganizationPackageSubscription::query()->count());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_success_before_checkout_saves_provider_id_binds_payment_and_activates(): void
    {
        $this->payment->forceFill(['provider_payment_id' => null])->save();
        $this->gateway->payment = $this->paymentResult();

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertSame('processed', $result);
        $this->assertSame('payment-id', $this->payment->fresh()->provider_payment_id);
        $this->assertSame('paid', $this->order->fresh()->status->value);
        $this->assertSame(1, OrganizationPackageSubscription::query()->count());
    }

    public function test_unknown_payment_before_local_binding_remains_retryable_without_event_marker(): void
    {
        $this->payment->forceFill(['provider_payment_id' => null])->save();
        $this->gateway->payment = $this->paymentResult(metadata: [
            'order_id' => '22222222-2222-4222-8222-222222222222',
            'organization_id' => $this->organization->id,
        ]);

        try {
            app(CommercialWebhookService::class)->process(
                $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
                '185.71.76.1',
            );
            $this->fail('Unbound local payment must remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Commercial payment is not locally bindable yet.', $exception->getMessage());
        }

        $this->assertSame(0, CommercialWebhookEvent::query()->count());
        $this->assertNull($this->payment->fresh()->provider_payment_id);
        $this->assertSame('pending_payment', $this->order->fresh()->status->value);
    }

    #[DataProvider('secondRenewalAttemptStatusProvider')]
    public function test_lost_response_binds_exact_second_renewal_attempt(string $status, bool $paid): void
    {
        [$cycle] = $this->configureRenewalCycle(now());
        $second = $this->createUnboundRenewalAttempt($cycle, 2);
        $this->gateway->payment = $this->paymentResult(
            status: $status,
            paid: $paid,
            id: 'renewal-attempt-2',
            cancellationReason: $status === 'canceled' ? 'insufficient_funds' : null,
        );

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.'.$status, 'renewal-attempt-2', $status),
            '185.71.76.1',
        );

        $this->assertSame('processed', $result);
        $this->assertSame('renewal-attempt-2', $second->fresh()->provider_payment_id);
        $this->assertSame($status, $second->fresh()->provider_status);
    }

    public static function secondRenewalAttemptStatusProvider(): array
    {
        return [
            'succeeded' => ['succeeded', true],
            'canceled' => ['canceled', false],
        ];
    }

    public function test_multiple_unbound_renewal_attempts_remain_retryable_without_binding(): void
    {
        [$cycle] = $this->configureRenewalCycle(now());
        $second = $this->createUnboundRenewalAttempt($cycle, 2);
        $third = $this->createUnboundRenewalAttempt($cycle, 3);
        $this->gateway->payment = $this->paymentResult(id: 'ambiguous-payment');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Commercial payment is not locally bindable yet.');

        try {
            app(CommercialWebhookService::class)->process(
                $this->notification('payment.succeeded', 'ambiguous-payment', 'succeeded'),
                '185.71.76.1',
            );
        } finally {
            $this->assertNull($second->fresh()->provider_payment_id);
            $this->assertNull($third->fresh()->provider_payment_id);
            $this->assertSame(0, CommercialWebhookEvent::query()->count());
        }
    }

    public function test_payment_cannot_activate_when_local_order_and_payment_amounts_diverge(): void
    {
        $this->order->forceFill(['amount_minor' => 800000, 'amount' => '8000.00'])->save();
        $this->gateway->payment = $this->paymentResult();

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertSame('mismatch', $result);
        $this->assertSame('pending_payment', $this->order->fresh()->status->value);
        $this->assertSame(0, OrganizationPackageSubscription::query()->count());
    }

    #[DataProvider('authoritativeMismatchProvider')]
    public function test_authoritative_success_mismatch_matrix_never_activates(array $override): void
    {
        $this->gateway->payment = $this->paymentResult(...$override);

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertContains($result, ['mismatch', 'stale']);
        $this->assertSame('pending_payment', $this->order->fresh()->status->value);
        $this->assertSame(0, OrganizationPackageSubscription::query()->count());
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(1, CommercialWebhookEvent::query()->count());
    }

    public static function authoritativeMismatchProvider(): array
    {
        return [
            'id' => [['id' => 'other-payment-id']],
            'status' => [['status' => 'canceled', 'paid' => false]],
            'paid' => [['paid' => false]],
            'test' => [['test' => false]],
            'amount' => [['amountMinor' => 790001]],
            'currency' => [['currency' => 'USD']],
            'order metadata' => [['metadata' => ['order_id' => 'other-order', 'organization_id' => 1]]],
            'organization metadata' => [['metadata' => ['order_id' => '11111111-1111-4111-8111-111111111111', 'organization_id' => 999]]],
        ];
    }

    public function test_full_suite_activates_exact_catalog_contour_and_preserves_unrelated_access(): void
    {
        $this->order->forceFill(['offer_type' => 'full_suite', 'selected_package_slugs' => []])->save();
        foreach ([
            ['slug' => 'corporate-extra', 'status' => 'active', 'source' => 'corporate'],
            ['slug' => 'trial-extra', 'status' => 'trialing', 'source' => 'trial'],
        ] as $row) {
            OrganizationPackageSubscription::create([
                'organization_id' => $this->organization->id, 'commercial_account_id' => $this->account->id,
                'package_slug' => $row['slug'], 'status' => $row['status'], 'access_source' => $row['source'],
                'price_paid' => 0, 'current_period_end_at' => $row['source'] === 'corporate' ? now()->addYear() : null,
                'trial_started_at' => $row['source'] === 'trial' ? now() : null,
                'trial_ends_at' => $row['source'] === 'trial' ? now()->addDays(3) : null,
            ]);
        }
        $this->gateway->payment = $this->paymentResult();

        app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertSame(10, OrganizationPackageSubscription::query()->where('access_source', 'full_suite')->count());
        $this->assertSame('active', OrganizationPackageSubscription::query()->where('package_slug', 'corporate-extra')->sole()->status->value);
        $this->assertSame('trialing', OrganizationPackageSubscription::query()->where('package_slug', 'trial-extra')->sole()->status->value);
    }

    public function test_saved_method_without_order_consent_does_not_enable_auto_renew(): void
    {
        $this->order->forceFill(['auto_renew_consent' => false])->save();
        $this->gateway->payment = $this->paymentResult(saved: true);

        app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertFalse($this->account->fresh()->auto_renew_enabled);
        $this->assertFalse($this->payment->fresh()->payment_method_saved);
        $this->assertNull($this->payment->fresh()->payment_method_id);
    }

    public function test_day_six_renewal_success_keeps_original_target_end_and_anchor(): void
    {
        [$cycle, $targetEnd] = $this->configureRenewalCycle(now()->subDays(6));
        $this->gateway->payment = $this->paymentResult(saved: false);

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertSame('processed', $result);
        $account = $this->account->fresh();
        $this->assertSame('active', $account->status->value);
        $this->assertSame($targetEnd->getTimestamp(), $account->current_period_end_at->getTimestamp());
        $this->assertSame($targetEnd->getTimestamp(), $account->billing_anchor_at->getTimestamp());
        $this->assertSame('paid', $cycle->fresh()->status);
        $remainingDays = now()->diffInDays($account->current_period_end_at);
        $this->assertGreaterThanOrEqual(23, $remainingDays);
        $this->assertLessThanOrEqual(25, $remainingDays);
        $this->assertSame('active', OrganizationPackageSubscription::query()->sole()->status->value);
    }

    public function test_late_duplicate_webhook_after_renewal_success_does_not_repeat_transition(): void
    {
        [$cycle] = $this->configureRenewalCycle(now()->subDay());
        $this->gateway->payment = $this->paymentResult(saved: false);
        $notification = $this->notification('payment.succeeded', 'payment-id', 'succeeded');
        $service = app(CommercialWebhookService::class);

        $this->assertSame('processed', $service->process($notification, '127.0.0.1'));
        $this->assertSame('duplicate', $service->process($notification, '185.71.76.1'));

        $this->assertSame('paid', $cycle->fresh()->status);
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_full_suite_renewal_activates_immutable_order_snapshot_only(): void
    {
        [$cycle] = $this->configureRenewalCycle(now()->subDay());
        $this->order->forceFill(['offer_type' => 'full_suite', 'selected_package_slugs' => ['machinery']])->save();
        $this->gateway->payment = $this->paymentResult(saved: false);

        app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertSame('paid', $cycle->fresh()->status);
        $this->assertSame(['machinery'], OrganizationPackageSubscription::query()->where('status', 'active')->pluck('package_slug')->all());
        $this->assertSame(1, OrganizationPackageSubscription::query()->count());
    }

    public function test_success_after_grace_is_flagged_for_manual_reconciliation_without_reactivation(): void
    {
        [$cycle] = $this->configureRenewalCycle(now()->subDays(8));
        $cycle->forceFill(['status' => 'suspended', 'suspended_at' => now()->subDay()])->save();
        $this->account->forceFill(['status' => 'suspended'])->save();
        $this->gateway->payment = $this->paymentResult(saved: false);

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.succeeded', 'payment-id', 'succeeded'),
            '185.71.76.1',
        );

        $this->assertSame('manual_review', $result);
        $this->assertSame('manual_review', $cycle->fresh()->status);
        $this->assertSame('pending_payment', $this->order->fresh()->status->value);
        $this->assertSame('suspended', $this->account->fresh()->status->value);
        $this->assertSame('grace', OrganizationPackageSubscription::query()->sole()->status->value);
        $this->assertSame(0, Notification::query()->count());
    }

    #[DataProvider('renewalCancellationReasonProvider')]
    public function test_renewal_cancellation_reason_controls_retry_policy(
        string $reason,
        string $category,
        string $cycleStatus,
        bool $autoRenewEnabled,
    ): void {
        [$cycle] = $this->configureRenewalCycle(now());
        $cycle->forceFill(['status' => 'due'])->save();
        $this->account->forceFill(['status' => 'active', 'grace_started_at' => null, 'grace_ends_at' => null])->save();
        OrganizationPackageSubscription::query()->update(['status' => 'active']);
        $this->gateway->payment = $this->paymentResult(
            status: 'canceled', paid: false, cancellationReason: $reason,
        );

        app(CommercialWebhookService::class)->process(
            $this->notification('payment.canceled', 'payment-id', 'canceled'),
            '185.71.76.1',
        );

        $this->assertSame($category, $this->payment->fresh()->failure_category);
        $this->assertSame($reason, $this->payment->fresh()->terminal_failure_reason);
        $this->assertSame($cycleStatus, $cycle->fresh()->status);
        $this->assertSame($autoRenewEnabled, $this->account->fresh()->auto_renew_enabled);
        $this->assertSame('pending_payment', $this->order->fresh()->status->value);
        $this->assertSame('grace', OrganizationPackageSubscription::query()->sole()->status->value);
        $expectedNotifications = $autoRenewEnabled ? 2 : 3;
        $this->assertSame($expectedNotifications, Notification::query()->count());
        $this->assertTrue(Notification::query()->get()->every(fn (Notification $notification): bool => $notification->channels === ['in_app']));
    }

    public static function renewalCancellationReasonProvider(): array
    {
        return [
            'revoked' => ['permission_revoked', 'method_revoked', 'disabled', false],
            'financial retry' => ['insufficient_funds', 'retryable', 'grace', true],
            'documented limit retry' => ['payment_method_limit_exceeded', 'retryable', 'grace', true],
            'expired card' => ['card_expired', 'non_retryable', 'disabled', false],
            'invalid card' => ['invalid_card_number', 'non_retryable', 'disabled', false],
            'restricted method' => ['payment_method_restricted', 'non_retryable', 'disabled', false],
            'fraud' => ['fraud_suspected', 'non_retryable', 'disabled', false],
            'unknown conservative' => ['unknown', 'non_retryable', 'disabled', false],
        ];
    }

    public function test_reduced_contour_cancellation_keeps_only_order_snapshot_in_grace(): void
    {
        [$cycle] = $this->configureRenewalCycle(now());
        $cycle->forceFill(['status' => 'due'])->save();
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $this->account->id,
            'package_slug' => 'planning-schedules',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 7900,
            'current_period_start_at' => $this->order->period_start_at->subDays(30),
            'current_period_end_at' => $this->order->period_start_at,
            'source_order_id' => $this->order->id,
        ]);
        $this->order->forceFill([
            'selected_package_slugs' => ['machinery'],
            'current_package_slugs' => ['machinery', 'planning-schedules'],
        ])->save();
        $this->gateway->payment = $this->paymentResult(
            status: 'canceled', paid: false, cancellationReason: 'insufficient_funds',
        );

        app(CommercialWebhookService::class)->process(
            $this->notification('payment.canceled', 'payment-id', 'canceled'),
            '185.71.76.1',
        );

        $statuses = OrganizationPackageSubscription::query()->pluck('status', 'package_slug');
        $this->assertSame('grace', $statuses['machinery']->value);
        $this->assertSame('expired', $statuses['planning-schedules']->value);
    }

    public function test_canceled_pending_order_notifies_once_but_cannot_downgrade_paid_order(): void
    {
        $this->gateway->payment = $this->paymentResult(status: 'canceled', paid: false);
        $service = app(CommercialWebhookService::class);

        $this->assertSame('processed', $service->process($this->notification('payment.canceled', 'payment-id', 'canceled'), '185.71.76.1'));
        $this->assertSame('canceled', $this->order->fresh()->status->value);
        $this->assertSame(1, Notification::query()->count());

        $this->order->forceFill(['status' => 'paid'])->save();
        $this->assertSame('duplicate', $service->process($this->notification('payment.canceled', 'payment-id', 'canceled'), '185.71.76.1'));
        $this->assertSame('paid', $this->order->fresh()->status->value);
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_fresh_stale_canceled_event_cannot_downgrade_succeeded_payment(): void
    {
        $this->order->forceFill(['status' => 'paid'])->save();
        $this->payment->forceFill(['provider_status' => 'succeeded'])->save();
        $this->gateway->payment = $this->paymentResult(status: 'canceled', paid: false);

        $result = app(CommercialWebhookService::class)->process(
            $this->notification('payment.canceled', 'payment-id', 'canceled'),
            '185.71.76.1',
        );

        $this->assertSame('stale', $result);
        $this->assertSame('paid', $this->order->fresh()->status->value);
        $this->assertSame('succeeded', $this->payment->fresh()->provider_status);
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_partial_refund_records_amount_and_full_refund_revokes_only_source_rows(): void
    {
        $this->order->forceFill(['status' => 'paid'])->save();
        $laterOrder = CommercialOrder::create([
            'public_id' => '33333333-3333-4333-8333-333333333333',
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'status' => 'paid', 'offer_type' => 'packages', 'quote_version' => 1,
            'selected_package_slugs' => ['planning-schedules'], 'current_package_slugs' => ['machinery'],
            'amount_minor' => 790000, 'amount' => '7900.00', 'currency' => 'RUB',
            'period_start_at' => now(), 'period_end_at' => now()->addMonth(),
            'auto_renew_consent' => false, 'client_idempotency_key' => 'later-order-key',
        ]);
        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id, 'commercial_account_id' => $this->account->id,
            'package_slug' => 'machinery', 'status' => 'active', 'access_source' => 'paid_package',
            'price_paid' => 7900, 'current_period_start_at' => now(), 'current_period_end_at' => now()->addMonth(),
            'source_order_id' => $this->order->id,
        ]);
        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id, 'commercial_account_id' => $this->account->id,
            'package_slug' => 'planning-schedules', 'status' => 'active', 'access_source' => 'paid_package',
            'price_paid' => 7900, 'current_period_start_at' => now(), 'current_period_end_at' => now()->addMonth(),
            'source_order_id' => $laterOrder->id,
        ]);
        $this->gateway->refund = $this->refundResult('refund-partial', 100000);
        $this->gateway->payment = $this->paymentResult(refundedAmountMinor: 100000);
        $service = app(CommercialWebhookService::class);

        $this->assertSame('partial_refund', $service->process($this->notification('refund.succeeded', 'refund-partial', 'succeeded'), '185.71.76.1'));
        $this->assertSame('paid', $this->order->fresh()->status->value);
        $this->assertSame('active', OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->sole()->status->value);

        $this->gateway->refund = $this->refundResult('refund-full', 690000);
        $this->gateway->payment = $this->paymentResult(refundedAmountMinor: 790000);
        $this->assertSame('full_refund', $service->process($this->notification('refund.succeeded', 'refund-full', 'succeeded'), '185.71.76.1'));
        $this->assertSame('refunded', $this->order->fresh()->status->value);
        $this->assertSame('expired', OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->sole()->status->value);
        $this->assertSame('active', OrganizationPackageSubscription::query()->where('package_slug', 'planning-schedules')->sole()->status->value);
        $this->assertSame(2, CommercialRefund::query()->count());
        $this->assertSame(2, Notification::query()->count());

        $endedRow = OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->sole();
        $endedAt = $endedRow->current_period_end_at;
        $canceledAt = $endedRow->canceled_at;
        $this->gateway->refund = $this->refundResult('refund-late-partial', 50000);
        $this->gateway->payment = $this->paymentResult(refundedAmountMinor: 100000);

        $this->assertSame('stale_refund', $service->process(
            $this->notification('refund.succeeded', 'refund-late-partial', 'succeeded'),
            '185.71.76.1',
        ));
        $this->assertSame(790000, $this->payment->fresh()->refunded_amount_minor);
        $this->assertSame('refunded', $this->order->fresh()->status->value);
        $this->assertEquals($endedAt, $endedRow->fresh()->current_period_end_at);
        $this->assertEquals($canceledAt, $endedRow->fresh()->canceled_at);
        $this->assertSame(2, Notification::query()->count());
        $this->assertSame(3, CommercialRefund::query()->count());
    }

    public function test_payment_method_active_is_idempotent_no_op_without_gateway_lookup(): void
    {
        $service = app(CommercialWebhookService::class);

        $this->assertSame('no_op', $service->process($this->notification('payment_method.active', 'method-id', 'bank_card'), '185.71.76.1'));
        $this->assertSame('duplicate', $service->process($this->notification('payment_method.active', 'method-id', 'bank_card'), '185.71.76.1'));
        $this->assertSame(0, $this->gateway->paymentLookups);
        $this->assertSame('pending_payment', $this->order->fresh()->status->value);
    }

    public function test_provider_lookup_failure_leaves_event_retryable(): void
    {
        $this->gateway->fail = true;

        try {
            app(CommercialWebhookService::class)->process($this->notification('payment.succeeded', 'payment-id', 'succeeded'), '185.71.76.1');
            $this->fail('Provider failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }

        $this->assertSame(0, CommercialWebhookEvent::query()->count());
    }

    private function notification(string $event, string $id, string $state): YooKassaWebhookNotification
    {
        return new YooKassaWebhookNotification($event, $id, $state, [
            'type' => 'notification', 'event' => $event, 'object' => ['id' => $id, 'status' => $state],
        ]);
    }

    private function paymentResult(
        string $status = 'succeeded',
        bool $paid = true,
        bool $saved = false,
        ?array $metadata = null,
        int $refundedAmountMinor = 0,
        string $id = 'payment-id',
        bool $test = true,
        int $amountMinor = 790000,
        string $currency = 'RUB',
        ?string $cancellationReason = null,
    ): PaymentGatewayResult {
        return new PaymentGatewayResult(
            id: $id, status: $status, confirmationUrl: null, paymentMethodId: 'method-id',
            paymentMethodSaved: $saved, safeResponse: ['id' => 'payment-id', 'status' => $status],
            paid: $paid, test: $test, amountMinor: $amountMinor, currency: $currency,
            metadata: $metadata ?? ['order_id' => $this->order->public_id, 'organization_id' => $this->organization->id],
            refundedAmountMinor: $refundedAmountMinor,
            cancellationReason: $cancellationReason,
        );
    }

    private function configureRenewalCycle($periodStart): array
    {
        $periodStart = \Carbon\CarbonImmutable::instance($periodStart);
        $targetEnd = $periodStart->addDays(30);
        $this->order->forceFill([
            'kind' => 'renewal', 'current_package_slugs' => ['machinery'],
            'period_start_at' => $periodStart, 'period_end_at' => $targetEnd,
        ])->save();
        $this->account->forceFill([
            'status' => 'grace', 'offer_type' => 'packages',
            'current_period_start_at' => $periodStart->subDays(30), 'current_period_end_at' => $periodStart,
            'billing_anchor_at' => $periodStart, 'auto_renew_enabled' => true,
            'saved_payment_method_id' => 'saved-method', 'saved_payment_method_active' => true,
            'grace_started_at' => $periodStart, 'grace_ends_at' => $periodStart->addDays(7),
        ])->save();
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $this->organization->id, 'commercial_account_id' => $this->account->id,
            'package_slug' => 'machinery', 'status' => 'grace', 'access_source' => 'paid_package',
            'price_paid' => 7900, 'current_period_start_at' => $periodStart->subDays(30),
            'current_period_end_at' => $periodStart, 'source_order_id' => $this->order->id,
        ]);
        $cycle = CommercialRenewalCycle::query()->create([
            'organization_id' => $this->organization->id, 'commercial_account_id' => $this->account->id,
            'commercial_order_id' => $this->order->id, 'status' => 'grace', 'due_at' => $periodStart,
            'billing_due_date' => $periodStart->setTimezone('Europe/Moscow')->toDateString(),
            'target_period_start_at' => $periodStart, 'target_period_end_at' => $targetEnd,
            'grace_deadline_at' => $periodStart->addDays(7), 'attempt_count' => 1,
        ]);
        $this->payment->forceFill(['role' => 'renewal', 'commercial_renewal_cycle_id' => $cycle->id])->save();

        return [$cycle, $targetEnd];
    }

    private function createUnboundRenewalAttempt(CommercialRenewalCycle $cycle, int $attempt): CommercialPayment
    {
        return CommercialPayment::query()->create([
            'commercial_order_id' => $this->order->id,
            'commercial_renewal_cycle_id' => $cycle->id,
            'role' => 'renewal',
            'attempt_number' => $attempt,
            'provider' => 'yookassa',
            'provider_payment_id' => null,
            'provider_status' => 'created',
            'amount_minor' => 790000,
            'currency' => 'RUB',
            'provider_idempotency_key' => 'attempt-'.$attempt,
            'payment_method_saved' => false,
            'refunded_amount_minor' => 0,
        ]);
    }

    private function refundResult(string $id, int $amountMinor): RefundGatewayResult
    {
        return new RefundGatewayResult($id, 'payment-id', 'succeeded', $amountMinor, 'RUB', ['id' => $id]);
    }

    private function createSchema(): void
    {
        foreach (['notifications', 'commercial_webhook_events', 'commercial_refunds', 'commercial_payments', 'commercial_renewal_cycles', 'commercial_orders', 'organization_package_trial_usages', 'organization_package_subscriptions', 'organization_commercial_accounts', 'users', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active');
            $table->boolean('is_verified');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('organization_commercial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique();
            $table->foreignId('responsible_user_id')->nullable();
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->timestamp('billing_anchor_at')->nullable();
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->boolean('auto_renew_enabled');
            $table->string('saved_payment_method_id')->nullable();
            $table->timestamp('saved_payment_method_at')->nullable();
            $table->boolean('saved_payment_method_active')->default(false);
            $table->timestamp('auto_renew_consented_at')->nullable();
            $table->string('auto_renew_terms_version')->nullable();
            $table->timestamp('grace_started_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('commercial_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->foreignId('user_id');
            $table->string('kind')->default('purchase');
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->json('selected_package_slugs');
            $table->json('current_package_slugs');
            $table->unsignedBigInteger('amount_minor');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->timestamp('period_start_at');
            $table->timestamp('period_end_at');
            $table->boolean('auto_renew_consent');
            $table->string('client_idempotency_key');
            $table->timestamps();
        });
        Schema::create('commercial_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id');
            $table->foreignId('commercial_renewal_cycle_id')->nullable();
            $table->string('provider');
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('provider_status');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->uuid('provider_idempotency_key')->unique();
            $table->text('confirmation_url')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->boolean('payment_method_saved');
            $table->json('safe_response')->nullable();
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->string('role')->default('initial');
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('terminal_failure_reason')->nullable();
            $table->string('failure_category')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->timestamps();
        });
        Schema::create('commercial_renewal_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->foreignId('commercial_order_id');
            $table->string('status');
            $table->timestamp('due_at');
            $table->date('billing_due_date');
            $table->timestamp('target_period_start_at');
            $table->timestamp('target_period_end_at');
            $table->timestamp('grace_deadline_at');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->string('package_slug');
            $table->string('status');
            $table->string('access_source');
            $table->decimal('price_paid', 12, 2);
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('source_order_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug']);
        });
        Schema::create('organization_package_trial_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('package_slug');
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug']);
        });
        Schema::create('commercial_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id');
            $table->foreignId('commercial_payment_id');
            $table->string('provider');
            $table->string('provider_refund_id')->unique();
            $table->string('provider_status');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->json('safe_response')->nullable();
            $table->timestamps();
        });
        Schema::create('commercial_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('event_name');
            $table->string('object_id');
            $table->string('authoritative_status')->nullable();
            $table->string('processing_result');
            $table->string('source_ip');
            $table->string('fingerprint')->unique();
            $table->json('safe_payload')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('notification_type');
            $table->string('priority');
            $table->json('channels');
            $table->json('delivery_status');
            $table->json('data');
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}

class AuthoritativeGatewayFake implements PaymentGatewayInterface
{
    public ?PaymentGatewayResult $payment = null;

    public ?RefundGatewayResult $refund = null;

    public bool $fail = false;

    public int $paymentLookups = 0;

    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        $this->paymentLookups++;
        if ($this->fail) {
            throw new RuntimeException('provider unavailable');
        }

        return $this->payment ?? throw new RuntimeException('payment missing');
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        if ($this->fail) {
            throw new RuntimeException('provider unavailable');
        }

        return $this->refund ?? throw new RuntimeException('refund missing');
    }
}
