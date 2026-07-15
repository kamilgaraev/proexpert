<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialContourChange;
use App\Models\CommercialOrder;
use App\Models\CommercialRenewalCycle;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CommercialCheckoutControllerTest extends TestCase
{
    private Organization $organization;

    private User $owner;

    private ControllerCheckoutGatewayFake $gateway;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.yookassa.mode', 'mock');

        $this->createSchema();
        $this->organization = Organization::withoutEvents(fn (): Organization => Organization::create([
            'name' => 'Checkout API organization',
            'is_active' => true,
            'is_verified' => true,
        ]));
        $this->owner = $this->createUser('owner-checkout@example.test');
        $this->attachRole($this->owner, 'organization_owner');
        $this->gateway = new ControllerCheckoutGatewayFake;
        $this->app->instance(PaymentGatewayInterface::class, $this->gateway);
    }

    public function test_owner_creates_checkout_through_protected_route(): void
    {
        $response = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/checkout',
            $this->payload(),
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.amount', '7900.00')
            ->assertJsonPath('data.amount_minor', 790000)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.confirmation_url', 'https://yookassa.test/confirmation');

        $this->assertDatabaseCount('commercial_orders', 1);
        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertDatabaseCount('organization_package_subscriptions', 0);
    }

    public function test_checkout_requires_token(): void
    {
        $this->postJson('/api/v1/landing/billing/commercial/checkout', $this->payload())
            ->assertUnauthorized();
    }

    public function test_checkout_denies_view_only_member(): void
    {
        $accountant = $this->createUser('accountant-checkout@example.test');
        $this->attachRole($accountant, 'accountant');

        $this->authenticatedAs($accountant)
            ->postJson('/api/v1/landing/billing/commercial/checkout', $this->payload())
            ->assertForbidden();
    }

    public function test_quote_uses_only_server_current_contour_of_current_organization(): void
    {
        $account = $this->commercialAccount();
        $this->package($account, 'machinery');
        $foreign = Organization::withoutEvents(fn (): Organization => Organization::create([
            'name' => 'Foreign organization', 'is_active' => true, 'is_verified' => true,
        ]));
        $foreignAccount = OrganizationCommercialAccount::query()->create([
            'organization_id' => $foreign->id, 'status' => 'active', 'offer_type' => 'packages',
            'quote_version' => 1, 'current_period_start_at' => now()->subDays(20),
            'current_period_end_at' => now()->addDays(10), 'auto_renew_enabled' => true,
        ]);
        $this->package($foreignAccount, 'planning-schedules');

        $response = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/quote',
            ['target_package_slugs' => ['machinery', 'planning-schedules'], 'full_suite' => false],
        );

        $response->assertOk()
            ->assertJsonPath('data.current_package_slugs', ['machinery'])
            ->assertJsonPath('data.added_package_slugs', ['planning-schedules'])
            ->assertJsonPath('data.removed_package_slugs', [])
            ->assertJsonPath('data.offer_type', 'packages')
            ->assertJsonPath('data.quote_version', 1);
    }

    public function test_quote_recommends_full_suite_at_eight_packages_but_never_selects_it_automatically(): void
    {
        $slugs = [
            'machinery', 'estimates-norms', 'finance-contracts', 'planning-schedules',
            'projects-processes', 'pto-handover', 'quality-safety', 'sales-contractors',
        ];

        $recommended = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/quote',
            ['target_package_slugs' => $slugs, 'full_suite' => false],
        );
        $selected = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/quote',
            ['target_package_slugs' => [], 'full_suite' => true],
        );

        $recommended->assertOk()
            ->assertJsonPath('data.offer_type', 'packages')
            ->assertJsonPath('data.recommendation', 'full_suite');
        $selected->assertOk()
            ->assertJsonPath('data.offer_type', 'full_suite')
            ->assertJsonPath('data.recommendation', null);
        $this->assertCount(10, $selected->json('data.target_package_slugs'));
    }

    public function test_quote_rejects_client_current_contour_and_period_boundaries(): void
    {
        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/quote',
            [
                'target_package_slugs' => ['machinery'],
                'full_suite' => false,
                'current_package_slugs' => ['planning-schedules'],
                'current_period_start_at' => '2026-01-01T00:00:00Z',
                'current_period_end_at' => '2026-01-31T00:00:00Z',
            ],
        )->assertUnprocessable();
    }

    public function test_quote_and_checkout_are_blocked_during_grace(): void
    {
        $this->commercialAccount()->forceFill(['status' => 'grace'])->save();

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/quote',
            ['target_package_slugs' => ['machinery'], 'full_suite' => false],
        )->assertConflict();
        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/checkout',
            $this->payload(),
        )->assertConflict();

        $this->assertDatabaseCount('commercial_orders', 0);
    }

    public function test_corporate_checkout_returns_translated_conflict_without_provider_call(): void
    {
        $this->commercialAccount()->forceFill([
            'status' => 'corporate',
            'offer_type' => 'corporate',
        ])->save();

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/checkout',
            $this->payload(),
        )->assertConflict()
            ->assertJsonPath('message', trans_message('billing.commercial.corporate_self_service_disabled'));

        $this->assertDatabaseCount('commercial_orders', 0);
        $this->assertDatabaseCount('commercial_payments', 0);
        $this->assertSame(0, $this->gateway->createPaymentCalls);
    }

    public function test_owner_creates_idempotent_manual_payment_for_fixed_grace_cycle(): void
    {
        [$account, $order, $cycle, $anchor] = $this->graceRenewal();
        $key = '33333333-3333-4333-8333-333333333333';

        $first = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => $key],
        );
        $second = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => $key],
        );

        $first->assertCreated()
            ->assertJsonPath('data.order_id', $order->public_id)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.confirmation_url', 'https://yookassa.test/confirmation')
            ->assertJsonPath('data.period_end_at', $anchor->toJSON());
        $second->assertOk()->assertJsonPath('data.order_id', $order->public_id);
        $this->assertDatabaseCount('commercial_orders', 1);
        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertSame($cycle->id, $order->payments()->firstOrFail()->commercial_renewal_cycle_id);
        $this->assertSame($key, $order->payments()->firstOrFail()->provider_idempotency_key);
        $this->assertCount(1, $this->gateway->createdPayments);
        $this->assertSame($order->public_id, $this->gateway->createdPayments[0]->metadata['order_id']);
        $this->assertSame($key, $this->gateway->createdPayments[0]->metadata['manual_payment_key']);
        $this->assertEquals($anchor, $account->fresh()->billing_anchor_at);
        $this->assertSame('grace', $account->fresh()->status->value);
    }

    public function test_manual_payment_with_different_key_reuses_open_confirmation(): void
    {
        [, $order] = $this->graceRenewal();

        $first = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '11111111-1111-4111-8111-111111111111'],
        );
        $second = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '22222222-2222-4222-8222-222222222222'],
        );

        $first->assertCreated();
        $second->assertOk()
            ->assertJsonPath('data.order_id', $order->public_id)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.confirmation_url', 'https://yookassa.test/confirmation');
        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertCount(1, $this->gateway->createdPayments);
    }

    public function test_manual_payment_does_not_create_another_attempt_while_provider_creation_is_in_flight(): void
    {
        [, $order, $cycle] = $this->graceRenewal();
        $order->payments()->create([
            'commercial_renewal_cycle_id' => $cycle->id,
            'role' => 'renewal',
            'attempt_number' => 1,
            'provider' => 'yookassa',
            'provider_status' => 'created',
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'provider_idempotency_key' => '11111111-1111-4111-8111-111111111111',
            'payment_method_saved' => false,
        ]);

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '22222222-2222-4222-8222-222222222222'],
        )->assertConflict();

        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertCount(0, $this->gateway->createdPayments);
    }

    public function test_canceled_manual_payment_allows_retry_with_a_new_key(): void
    {
        $this->graceRenewal();
        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '11111111-1111-4111-8111-111111111111'],
        )->assertCreated();
        $firstPayment = \App\Models\CommercialPayment::query()->sole();
        $firstPayment->forceFill([
            'provider_status' => 'canceled',
            'confirmation_url' => null,
            'terminal_at' => now(),
        ])->save();

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '22222222-2222-4222-8222-222222222222'],
        )->assertCreated()->assertJsonPath('data.payment_status', 'pending');

        $this->assertDatabaseCount('commercial_payments', 2);
        $this->assertCount(2, $this->gateway->createdPayments);
    }

    public function test_succeeded_manual_payment_blocks_another_attempt(): void
    {
        $this->graceRenewal();
        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '11111111-1111-4111-8111-111111111111'],
        )->assertCreated();
        \App\Models\CommercialPayment::query()->sole()->forceFill([
            'provider_status' => 'succeeded',
            'confirmation_url' => null,
            'terminal_at' => now(),
        ])->save();

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '22222222-2222-4222-8222-222222222222'],
        )->assertConflict();

        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertCount(1, $this->gateway->createdPayments);
    }

    public function test_same_manual_payment_key_recovers_unknown_provider_result_without_new_row(): void
    {
        $this->graceRenewal();
        $this->gateway->failuresRemaining = 1;
        $key = '11111111-1111-4111-8111-111111111111';

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => $key],
        )->assertServerError();
        $this->assertDatabaseHas('commercial_payments', [
            'provider_idempotency_key' => $key,
            'provider_status' => 'unknown',
        ]);

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => $key],
        )->assertOk()->assertJsonPath('data.confirmation_url', 'https://yookassa.test/confirmation');

        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertSame(2, $this->gateway->createPaymentCalls);
        $this->assertCount(1, $this->gateway->createdPayments);
    }

    public function test_database_rejects_parallel_open_renewal_attempts(): void
    {
        [, $order, $cycle] = $this->graceRenewal();
        $attributes = [
            'commercial_renewal_cycle_id' => $cycle->id,
            'role' => 'renewal',
            'provider' => 'yookassa',
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'payment_method_saved' => false,
        ];
        $order->payments()->create($attributes + [
            'attempt_number' => 1,
            'provider_status' => 'created',
            'provider_idempotency_key' => '11111111-1111-4111-8111-111111111111',
        ]);

        $this->expectException(QueryException::class);
        $order->payments()->create($attributes + [
            'attempt_number' => 2,
            'provider_status' => 'pending',
            'provider_idempotency_key' => '22222222-2222-4222-8222-222222222222',
        ]);
    }

    public function test_manual_payment_requires_active_grace_deadline(): void
    {
        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '55555555-5555-4555-8555-555555555555'],
        )->assertConflict();

        $this->assertCount(0, $this->gateway->createdPayments);
    }

    public function test_manual_payment_denies_view_only_member(): void
    {
        $accountant = $this->createUser('manual-payment-view@example.test');
        $this->attachRole($accountant, 'accountant');

        $this->authenticatedAs($accountant)->postJson(
            '/api/v1/landing/billing/commercial/renewal/manual-payment',
            ['client_idempotency_key' => '44444444-4444-4444-8444-444444444444'],
        )->assertForbidden();
        $this->assertCount(0, $this->gateway->createdPayments);
    }

    public function test_order_status_is_tenant_isolated_safe_and_has_no_entitlement_side_effect(): void
    {
        [$order, $payment] = $this->commercialOrder($this->organization, $this->commercialAccount(), 'pending_payment');
        $payment->forceFill([
            'provider_status' => 'pending',
            'confirmation_url' => 'https://yookassa.test/safe-confirmation',
            'payment_method_id' => 'must-not-leak',
            'safe_response' => ['secret' => 'must-not-leak'],
        ])->save();

        $this->getJson('/api/v1/landing/billing/commercial/orders/'.$order->public_id)->assertUnauthorized();
        $response = $this->authenticatedAs($this->owner)->getJson(
            '/api/v1/landing/billing/commercial/orders/'.$order->public_id,
        );

        $response->assertOk()
            ->assertJsonPath('data.order_id', $order->public_id)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.confirmation_url', 'https://yookassa.test/safe-confirmation')
            ->assertJsonMissingPath('data.payment_method_id')
            ->assertJsonMissingPath('data.safe_response');
        $this->assertDatabaseCount('organization_package_subscriptions', 0);

        $foreign = Organization::withoutEvents(fn (): Organization => Organization::create([
            'name' => 'Status foreign', 'is_active' => true, 'is_verified' => true,
        ]));
        $foreignAccount = OrganizationCommercialAccount::query()->create([
            'organization_id' => $foreign->id, 'status' => 'active', 'offer_type' => 'packages',
            'quote_version' => 1, 'auto_renew_enabled' => false,
        ]);
        [$foreignOrder] = $this->commercialOrder($foreign, $foreignAccount, 'paid');
        $this->authenticatedAs($this->owner)
            ->getJson('/api/v1/landing/billing/commercial/orders/'.$foreignOrder->public_id)
            ->assertNotFound();
    }

    public function test_history_is_paginated_newest_first_tenant_isolated_and_safe(): void
    {
        $account = $this->commercialAccount();
        [$old] = $this->commercialOrder($this->organization, $account, 'paid', '2026-07-01 10:00:00');
        [$middle, $payment] = $this->commercialOrder($this->organization, $account, 'canceled', '2026-07-02 10:00:00');
        [$new] = $this->commercialOrder($this->organization, $account, 'pending_payment', '2026-07-03 10:00:00');
        $payment->forceFill(['safe_response' => ['secret' => 'hidden'], 'payment_method_id' => 'hidden'])->save();
        $middle->refunds()->create([
            'commercial_payment_id' => $payment->id, 'provider' => 'yookassa',
            'provider_refund_id' => 'refund-safe', 'provider_status' => 'succeeded',
            'amount_minor' => 10000, 'currency' => 'RUB', 'safe_response' => ['secret' => 'hidden'],
        ]);
        $foreign = Organization::withoutEvents(fn (): Organization => Organization::create([
            'name' => 'History foreign', 'is_active' => true, 'is_verified' => true,
        ]));
        $foreignAccount = OrganizationCommercialAccount::query()->create([
            'organization_id' => $foreign->id, 'status' => 'active', 'offer_type' => 'packages',
            'quote_version' => 1, 'auto_renew_enabled' => false,
        ]);
        $this->commercialOrder($foreign, $foreignAccount, 'paid', '2026-07-04 10:00:00');

        $response = $this->authenticatedAs($this->owner)
            ->getJson('/api/v1/landing/billing/commercial/history?per_page=2&page=1');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.order_id', $new->public_id)
            ->assertJsonPath('data.1.order_id', $middle->public_id)
            ->assertJsonPath('data.1.refunds.0.amount_minor', 10000)
            ->assertJsonMissingPath('data.1.payments.0.safe_response')
            ->assertJsonMissingPath('data.1.payments.0.payment_method_id')
            ->assertJsonMissingPath('data.1.refunds.0.safe_response');
        $this->assertNotSame($old->public_id, $response->json('data.0.order_id'));
    }

    public function test_refunded_order_status_keeps_paid_timestamp_and_refund_summary(): void
    {
        [$order, $payment] = $this->commercialOrder(
            $this->organization,
            $this->commercialAccount(),
            'refunded',
        );
        $paidAt = CarbonImmutable::parse('2026-07-12 10:15:00');
        $payment->forceFill(['provider_status' => 'succeeded', 'terminal_at' => $paidAt])->save();
        $order->refunds()->create([
            'commercial_payment_id' => $payment->id,
            'provider' => 'yookassa',
            'provider_refund_id' => 'refund-status-summary',
            'provider_status' => 'succeeded',
            'amount_minor' => 790000,
            'currency' => 'RUB',
        ]);

        $this->authenticatedAs($this->owner)
            ->getJson('/api/v1/landing/billing/commercial/orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('data.paid_at', $paidAt->toJSON())
            ->assertJsonPath('data.refunds_summary.count', 1)
            ->assertJsonPath('data.refunds_summary.amount_minor', 790000)
            ->assertJsonPath('data.refunds_summary.fully_refunded', true);
    }

    public function test_canceled_renewal_attempt_is_exposed_as_failed_terminal_status(): void
    {
        $account = $this->commercialAccount();
        [$order, $payment] = $this->commercialOrder($this->organization, $account, 'pending_payment');
        $order->forceFill(['kind' => 'renewal'])->save();
        $payment->forceFill(['provider_status' => 'canceled', 'terminal_at' => now()])->save();
        CommercialRenewalCycle::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'commercial_order_id' => $order->id,
            'status' => 'grace',
            'due_at' => now()->subDay(),
            'target_period_start_at' => $order->period_start_at,
            'target_period_end_at' => $order->period_end_at,
            'grace_deadline_at' => now()->addDays(6),
            'attempt_count' => 1,
        ]);

        $this->authenticatedAs($this->owner)
            ->getJson('/api/v1/landing/billing/commercial/orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.payment_status', 'canceled');
    }

    public function test_removal_is_scheduled_idempotently_for_fixed_anchor_without_revoking_current_access(): void
    {
        CarbonImmutable::setTestNow('2026-07-14 12:00:00');
        $account = $this->commercialAccount();
        $anchor = CarbonImmutable::parse('2026-07-24 12:00:00');
        $account->forceFill(['current_period_end_at' => $anchor, 'billing_anchor_at' => $anchor])->save();
        $kept = $this->package($account, 'machinery', $anchor);
        $removed = $this->package($account, 'planning-schedules', $anchor);
        $payload = [
            'target_package_slugs' => ['machinery'], 'full_suite' => false,
            'quote_version' => 1, 'client_idempotency_key' => 'schedule-removal-00000000000000000001',
        ];

        $first = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/contour/schedule', $payload,
        );
        $second = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/contour/schedule', $payload,
        );

        $first->assertCreated()
            ->assertJsonPath('data.apply_at', $anchor->toJSON())
            ->assertJsonPath('data.target_package_slugs', ['machinery']);
        $second->assertOk()->assertJsonPath('data.change_id', $first->json('data.change_id'));
        $this->assertDatabaseCount('commercial_contour_changes', 1);
        $this->assertSame('active', $kept->fresh()->status->value);
        $this->assertSame('scheduled_for_removal', $removed->fresh()->status->value);
        $this->assertEquals($anchor, $removed->fresh()->current_period_end_at);
        $this->assertTrue($removed->fresh()->isActive());

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/contour/schedule',
            array_replace($payload, ['client_idempotency_key' => 'schedule-removal-00000000000000000002']),
        )->assertConflict();
        $this->assertDatabaseCount('commercial_contour_changes', 1);
    }

    public function test_corporate_account_cannot_schedule_self_service_contour_change(): void
    {
        $account = $this->commercialAccount();
        $account->forceFill([
            'status' => 'corporate',
            'offer_type' => 'corporate',
            'current_period_start_at' => now()->subDay(),
            'current_period_end_at' => now()->addMonth(),
        ])->save();
        $periodEnd = CarbonImmutable::instance($account->current_period_end_at);
        $this->package($account, 'machinery', $periodEnd);
        $this->package($account, 'planning-schedules', $periodEnd);

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/contour/schedule',
            [
                'target_package_slugs' => ['machinery'],
                'full_suite' => false,
                'quote_version' => 1,
                'client_idempotency_key' => 'corporate-schedule-000000000000000001',
            ],
        )->assertConflict()
            ->assertJsonPath('message', trans_message('billing.commercial.corporate_self_service_disabled'));

        $this->assertDatabaseCount('commercial_contour_changes', 0);
    }

    public function test_contour_schedule_is_blocked_during_grace(): void
    {
        $account = $this->commercialAccount();
        $this->package($account, 'machinery');
        $this->package($account, 'planning-schedules');
        $account->forceFill([
            'status' => 'grace',
            'grace_started_at' => now()->subHour(),
            'grace_ends_at' => now()->addDays(7),
        ])->save();

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/contour/schedule',
            [
                'target_package_slugs' => ['machinery'],
                'full_suite' => false,
                'quote_version' => 1,
                'client_idempotency_key' => 'schedule-grace-000000000000000000001',
            ],
        )->assertConflict();

        $this->assertDatabaseCount('commercial_contour_changes', 0);
        $this->assertSame(
            ['active', 'active'],
            OrganizationPackageSubscription::query()
                ->orderBy('package_slug')
                ->get()
                ->map(static fn (OrganizationPackageSubscription $package): string => $package->status->value)
                ->all(),
        );
    }

    public function test_postgres_deadlock_during_schedule_is_mapped_to_business_conflict(): void
    {
        $account = $this->commercialAccount();
        $this->package($account, 'machinery');
        $this->package($account, 'planning-schedules');
        CommercialContourChange::creating(function (): void {
            $message = 'deadlock detected';
            $previous = new \PDOException($message, 40_001);
            $previous->errorInfo = ['40P01', '7', $message];

            throw new QueryException('pgsql', 'insert into commercial_contour_changes', [], $previous);
        });

        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/contour/schedule',
            [
                'target_package_slugs' => ['machinery'],
                'full_suite' => false,
                'quote_version' => 1,
                'client_idempotency_key' => 'schedule-deadlock-0000000000000000001',
            ],
        )->assertConflict();

        $this->assertDatabaseCount('commercial_contour_changes', 0);
        $this->assertSame('active', $account->fresh()->status->value);
    }

    public function test_checkout_does_not_accept_client_period_boundaries(): void
    {
        $this->authenticatedAs($this->owner)
            ->postJson('/api/v1/landing/billing/commercial/checkout', $this->payload() + [
                'current_period_start_at' => '2026-01-01T00:00:00Z',
            ])
            ->assertUnprocessable();
    }

    public function test_full_suite_rejects_nonempty_target_package_list(): void
    {
        $this->authenticatedAs($this->owner)
            ->postJson('/api/v1/landing/billing/commercial/checkout', array_replace($this->payload(), [
                'full_suite' => true,
            ]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('commercial_orders', 0);
    }

    public function test_renewal_state_requires_token_and_returns_only_safe_fields_to_view_member(): void
    {
        $this->getJson('/api/v1/landing/billing/commercial/renewal')->assertUnauthorized();
        $accountant = $this->createUser('renewal-view@example.test');
        $this->attachRole($accountant, 'accountant');
        $this->commercialAccount();

        $response = $this->authenticatedAs($accountant)->getJson('/api/v1/landing/billing/commercial/renewal');

        $response->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.auto_renew_enabled', true)
            ->assertJsonPath('data.saved_method_available', true)
            ->assertJsonMissingPath('data.saved_payment_method_id');
        $this->assertSame([
            'status', 'auto_renew_enabled', 'saved_method_available', 'next_billing_at',
            'grace_started_at', 'grace_ends_at', 'retry_status', 'attempt_count', 'next_attempt_at',
            'scheduled_change',
        ], array_keys($response->json('data')));
    }

    public function test_renewal_state_restores_scheduled_reduction_with_fixed_anchor(): void
    {
        $account = $this->commercialAccount();
        $anchor = CarbonImmutable::parse('2026-08-14 12:00:00');
        $account->forceFill(['billing_anchor_at' => $anchor, 'current_period_end_at' => $anchor])->save();
        CommercialContourChange::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'user_id' => $this->owner->id,
            'status' => 'scheduled',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'target_package_slugs' => ['machinery'],
            'current_package_slugs' => ['machinery', 'planning-schedules'],
            'apply_at' => $anchor,
            'client_idempotency_key' => 'schedule-state-000000000000000000001',
        ]);

        $this->authenticatedAs($this->owner)
            ->getJson('/api/v1/landing/billing/commercial/renewal')
            ->assertOk()
            ->assertJsonPath('data.scheduled_change.status', 'scheduled')
            ->assertJsonPath('data.scheduled_change.target_package_slugs', ['machinery'])
            ->assertJsonPath('data.scheduled_change.apply_at', $anchor->toJSON())
            ->assertJsonPath('data.scheduled_change.billing_anchor_at', $anchor->toJSON());
    }

    public function test_renewal_state_denies_user_without_organization_membership(): void
    {
        $outsider = User::create([
            'name' => 'Outsider', 'email' => 'renewal-outsider@example.test', 'password' => 'password',
            'is_active' => true, 'current_organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
        $this->authenticatedAs($outsider)->getJson('/api/v1/landing/billing/commercial/renewal')->assertForbidden();
    }

    public function test_disable_requires_manage_and_is_idempotent_without_revoking_paid_period(): void
    {
        $account = $this->commercialAccount();
        $paidEnd = $account->current_period_end_at;

        $first = $this->authenticatedAs($this->owner)->postJson('/api/v1/landing/billing/commercial/renewal/disable');
        $second = $this->authenticatedAs($this->owner)->postJson('/api/v1/landing/billing/commercial/renewal/disable');

        $first->assertOk()->assertJsonPath('data.auto_renew_enabled', false)->assertJsonPath('data.saved_method_available', false);
        $second->assertOk()->assertJsonPath('data.auto_renew_enabled', false);
        $fresh = $account->fresh();
        $this->assertSame('active', $fresh->status->value);
        $this->assertEquals($paidEnd, $fresh->current_period_end_at);
        $this->assertSame('provider-method-audit', $fresh->saved_payment_method_id);
    }

    public function test_disable_denies_view_only_member(): void
    {
        $accountant = $this->createUser('renewal-disable-view@example.test');
        $this->attachRole($accountant, 'accountant');
        $this->authenticatedAs($accountant)
            ->postJson('/api/v1/landing/billing/commercial/renewal/disable')
            ->assertForbidden();
    }

    private function commercialAccount(): OrganizationCommercialAccount
    {
        return OrganizationCommercialAccount::query()->firstOrCreate(['organization_id' => $this->organization->id], [
            'responsible_user_id' => $this->owner->id, 'status' => 'active', 'offer_type' => 'packages',
            'quote_version' => 1, 'billing_anchor_at' => now()->addDays(10),
            'current_period_start_at' => now()->subDays(20), 'current_period_end_at' => now()->addDays(10),
            'auto_renew_enabled' => true, 'saved_payment_method_id' => 'provider-method-audit',
            'saved_payment_method_active' => true,
        ]);
    }

    private function graceRenewal(): array
    {
        CarbonImmutable::setTestNow('2026-07-20 12:00:00');
        $account = $this->commercialAccount();
        $anchor = CarbonImmutable::parse('2026-08-13 12:00:00');
        $account->forceFill([
            'status' => 'grace',
            'current_period_start_at' => '2026-06-14 12:00:00',
            'current_period_end_at' => '2026-07-14 12:00:00',
            'billing_anchor_at' => $anchor,
            'grace_started_at' => '2026-07-14 12:00:00',
            'grace_ends_at' => '2026-07-21 12:00:00',
        ])->save();
        [$order] = $this->commercialOrder($this->organization, $account, 'pending_payment');
        $order->forceFill([
            'kind' => 'renewal',
            'selected_package_slugs' => ['machinery'],
            'current_package_slugs' => ['machinery'],
            'period_start_at' => '2026-07-14 12:00:00',
            'period_end_at' => $anchor,
        ])->save();
        $order->payments()->delete();
        $cycle = CommercialRenewalCycle::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'commercial_order_id' => $order->id,
            'status' => 'grace',
            'due_at' => '2026-07-14 12:00:00',
            'target_period_start_at' => '2026-07-14 12:00:00',
            'target_period_end_at' => $anchor,
            'grace_deadline_at' => '2026-07-21 12:00:00',
            'attempt_count' => 1,
        ]);

        return [$account, $order, $cycle, $anchor];
    }

    private function payload(): array
    {
        return [
            'target_package_slugs' => ['machinery'],
            'current_package_slugs' => [],
            'full_suite' => false,
            'quote_version' => 1,
            'client_idempotency_key' => '22222222-2222-4222-8222-222222222222',
            'auto_renew_consent' => true,
        ];
    }

    private function package(
        OrganizationCommercialAccount $account,
        string $slug,
        ?CarbonImmutable $periodEnd = null,
    ): OrganizationPackageSubscription {
        return OrganizationPackageSubscription::query()->create([
            'organization_id' => $account->organization_id,
            'commercial_account_id' => $account->id,
            'package_slug' => $slug,
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 7900,
            'current_period_start_at' => now()->subDays(20),
            'current_period_end_at' => $periodEnd ?? now()->addDays(10),
        ]);
    }

    private function commercialOrder(
        Organization $organization,
        OrganizationCommercialAccount $account,
        string $status,
        ?string $createdAt = null,
    ): array {
        $order = CommercialOrder::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $organization->id,
            'commercial_account_id' => $account->id,
            'user_id' => $this->owner->id,
            'kind' => 'purchase',
            'status' => $status,
            'offer_type' => 'packages',
            'quote_version' => 1,
            'selected_package_slugs' => ['machinery'],
            'current_package_slugs' => [],
            'amount_minor' => 790000,
            'amount' => '7900.00',
            'currency' => 'RUB',
            'period_start_at' => now(),
            'period_end_at' => now()->addDays(30),
            'auto_renew_consent' => true,
            'client_idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }
        $payment = $order->payments()->create([
            'provider' => 'yookassa', 'provider_status' => $status === 'paid' ? 'succeeded' : $status,
            'amount_minor' => 790000, 'currency' => 'RUB',
            'provider_idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payment_method_saved' => false,
        ]);

        return [$order, $payment];
    }

    private function authenticatedAs(User $user): self
    {
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])->fromUser($user);

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function createUser(string $email): User
    {
        $user = User::create([
            'name' => 'Checkout user',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'current_organization_id' => $this->organization->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->organizations()->attach($this->organization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        return $user;
    }

    private function attachRole(User $user, string $role): void
    {
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_slug' => $role,
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($this->organization->id)->id,
            'is_active' => true,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'commercial_refunds', 'commercial_contour_changes', 'commercial_payments', 'commercial_renewal_cycles', 'commercial_orders', 'organization_package_subscriptions',
            'organization_commercial_accounts', 'organization_module_activations', 'modules',
            'role_conditions', 'user_role_assignments',
            'organization_custom_roles', 'authorization_contexts', 'organization_user', 'users', 'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_holding')->default(false);
            $table->foreignId('parent_organization_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->string('project_access_mode')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'organization_id']);
        });
        Schema::create('authorization_contexts', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            $table->unsignedBigInteger('parent_context_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_custom_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->json('system_permissions');
            $table->json('module_permissions');
            $table->json('interface_access');
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('user_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role_slug', 100)->index();
            $table->string('role_type')->default('system');
            $table->unsignedBigInteger('context_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['user_id', 'role_slug', 'context_id'], 'unique_user_role_context');
        });
        Schema::create('role_conditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->string('condition_type')->index();
            $table->json('condition_data');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('current_organization_id')->nullable();
            $table->boolean('is_active')->default(true);
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
        });
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version')->default('1.0.0');
            $table->string('type')->default('feature');
            $table->string('billing_model')->default('subscription');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->json('pricing_config')->nullable();
            $table->json('features')->nullable();
            $table->json('permissions')->nullable();
            $table->json('dependencies')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('limits')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_module')->default(false);
            $table->boolean('can_deactivate')->default(true);
            $table->timestamps();
        });
        Schema::create('organization_module_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('module_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->json('module_settings')->nullable();
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
            $table->string('client_idempotency_key', 100);
            $table->string('server_idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_idempotency_key']);
        });
        Schema::create('commercial_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id');
            $table->foreignId('commercial_renewal_cycle_id')->nullable();
            $table->string('role')->default('initial');
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('provider');
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('provider_status');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->uuid('provider_idempotency_key')->unique();
            $table->text('confirmation_url')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->boolean('payment_method_saved')->default(false);
            $table->json('safe_response')->nullable();
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->boolean('reconciliation_required')->default(false);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->timestamps();
        });
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
CREATE UNIQUE INDEX commercial_payments_one_open_renewal_attempt_unique
ON commercial_payments (commercial_order_id)
WHERE role = 'renewal'
  AND provider_status IN ('created', 'pending', 'waiting_for_capture', 'unknown', 'succeeded')
SQL);
        Schema::create('commercial_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id');
            $table->foreignId('commercial_payment_id');
            $table->string('provider');
            $table->string('provider_refund_id');
            $table->string('provider_status');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->json('safe_response')->nullable();
            $table->timestamps();
        });
        Schema::create('commercial_contour_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->foreignId('user_id');
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->json('target_package_slugs');
            $table->json('current_package_slugs');
            $table->timestamp('apply_at');
            $table->string('client_idempotency_key');
            $table->foreignId('commercial_order_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_idempotency_key']);
            $table->unique(['commercial_account_id', 'apply_at']);
        });
        Schema::create('commercial_renewal_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->foreignId('commercial_order_id');
            $table->string('status');
            $table->timestamp('due_at');
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
    }
}

class ControllerCheckoutGatewayFake implements PaymentGatewayInterface
{
    public array $createdPayments = [];

    public int $createPaymentCalls = 0;

    public int $failuresRemaining = 0;

    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        $this->createPaymentCalls++;
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new RuntimeException('Provider result is unknown.');
        }
        $this->createdPayments[] = $payment;
        $providerId = 'provider-api-payment-'.count($this->createdPayments);

        return new PaymentGatewayResult(
            id: $providerId,
            status: 'pending',
            confirmationUrl: 'https://yookassa.test/confirmation',
            paymentMethodId: null,
            paymentMethodSaved: false,
            safeResponse: ['id' => $providerId, 'status' => 'pending'],
        );
    }

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        throw new \RuntimeException('Not used.');
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        throw new \RuntimeException('Not used.');
    }

    public function createRefund(\App\DataTransferObjects\Billing\CreateRefundData $refund): RefundGatewayResult
    {
        throw new RuntimeException('Not used.');
    }
}
