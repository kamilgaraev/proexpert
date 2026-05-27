<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemAdmin;
use App\Services\Filament\SubscriptionAdminActionService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BillingOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SystemAdminRoleService::class)->clearCache();
    }

    protected function tearDown(): void
    {
        Auth::guard('system_admin')->logout();
        app(SystemAdminRoleService::class)->clearCache();

        parent::tearDown();
    }

    public function test_it_marks_subscription_cancellation_at_period_end_and_records_audit_event(): void
    {
        [$admin, $subscription] = $this->subscriptionFixture([
            'is_auto_payment_enabled' => true,
            'canceled_at' => null,
        ]);

        $event = app(SubscriptionAdminActionService::class)->cancelAtPeriodEnd(
            subscription: $subscription,
            actor: $admin,
            reason: 'customer_requested',
        );

        $subscription->refresh();

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertNotNull($subscription->canceled_at);
        $this->assertFalse($subscription->is_auto_payment_enabled);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'organization_id' => $subscription->organization_id,
            'actor_type' => 'system_admin',
            'event_type' => 'system_admin.subscriptions.cancellation_scheduled',
            'action' => 'updated',
            'subject_type' => OrganizationSubscription::class,
            'subject_id' => $subscription->id,
        ]);
        $this->assertSame('customer_requested', $event->context['reason']);
    }

    public function test_it_reactivates_subscription_and_records_audit_event(): void
    {
        [$admin, $subscription] = $this->subscriptionFixture([
            'status' => 'canceled',
            'is_auto_payment_enabled' => false,
            'canceled_at' => now()->subDay(),
        ]);

        $event = app(SubscriptionAdminActionService::class)->reactivate($subscription, $admin);

        $subscription->refresh();

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->canceled_at);
        $this->assertTrue($subscription->is_auto_payment_enabled);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'event_type' => 'system_admin.subscriptions.reactivated',
            'action' => 'updated',
            'subject_type' => OrganizationSubscription::class,
            'subject_id' => $subscription->id,
        ]);
    }

    public function test_it_grants_and_revokes_manual_subscription_extension_with_audit(): void
    {
        [$admin, $subscription] = $this->subscriptionFixture([
            'ends_at' => now()->addDays(5),
            'next_billing_at' => now()->addDays(5),
            'enterprise_constructor_config' => [],
        ]);
        $originalEndsAt = $subscription->ends_at?->toISOString();

        $grantEvent = app(SubscriptionAdminActionService::class)->grantManualExtension(
            subscription: $subscription,
            actor: $admin,
            days: 10,
            reason: 'loyalty_adjustment',
        );

        $subscription->refresh();

        $this->assertInstanceOf(ActivityEvent::class, $grantEvent);
        $this->assertSame('loyalty_adjustment', $subscription->enterprise_constructor_config['manual_extension']['reason']);
        $this->assertSame($originalEndsAt, $subscription->enterprise_constructor_config['manual_extension']['previous_ends_at']);
        $this->assertDatabaseHas('activity_events', [
            'id' => $grantEvent->id,
            'event_type' => 'system_admin.subscriptions.manual_extension_granted',
        ]);

        $revokeEvent = app(SubscriptionAdminActionService::class)->revokeManualExtension(
            subscription: $subscription,
            actor: $admin,
            reason: 'operator_rollback',
        );

        $subscription->refresh();

        $this->assertInstanceOf(ActivityEvent::class, $revokeEvent);
        $this->assertSame($originalEndsAt, $subscription->ends_at?->toISOString());
        $this->assertArrayNotHasKey('manual_extension', $subscription->enterprise_constructor_config ?? []);
        $this->assertDatabaseHas('activity_events', [
            'id' => $revokeEvent->id,
            'event_type' => 'system_admin.subscriptions.manual_extension_revoked',
        ]);
    }

    public function test_billing_resources_expose_safe_operations_and_read_only_transactions(): void
    {
        $subscriptionSource = file_get_contents(app_path('Filament/Resources/OrganizationSubscriptionResource.php'));
        $paymentSource = file_get_contents(app_path('Filament/Resources/PaymentTransactionResource.php'));
        $planSource = file_get_contents(app_path('Filament/Resources/SubscriptionPlanResource.php'));

        $this->assertIsString($subscriptionSource);
        $this->assertStringContainsString("Action::make('cancel_at_period_end')", $subscriptionSource);
        $this->assertStringContainsString("Action::make('reactivate')", $subscriptionSource);
        $this->assertStringContainsString("Action::make('grant_manual_extension')", $subscriptionSource);
        $this->assertStringContainsString("Action::make('revoke_manual_extension')", $subscriptionSource);
        $this->assertStringContainsString('FilamentPermission::SUBSCRIPTIONS_MANAGE', $subscriptionSource);
        $this->assertStringContainsString('->bulkActions([])', $subscriptionSource);

        $this->assertIsString($paymentSource);
        $this->assertStringContainsString('public static function canCreate(): bool', $paymentSource);
        $this->assertStringContainsString('return false;', $paymentSource);
        $this->assertStringContainsString('ViewAction::make()', $paymentSource);
        $this->assertStringNotContainsString('EditAction::make()', $paymentSource);

        $this->assertIsString($planSource);
        $this->assertStringContainsString('ViewAction::make()', $planSource);
        $this->assertStringContainsString('Pages\\ViewSubscriptionPlan::route', $planSource);
    }

    /**
     * @param array<string, mixed> $subscriptionOverrides
     * @return array{0: SystemAdmin, 1: OrganizationSubscription}
     */
    private function subscriptionFixture(array $subscriptionOverrides = []): array
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $organization = Organization::factory()->create();
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Billing Business',
            'slug' => 'billing-business-' . $organization->id,
            'price' => 9900,
            'currency' => 'RUB',
            'is_active' => true,
        ]);

        $subscription = OrganizationSubscription::query()->create(array_merge([
            'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
            'next_billing_at' => now()->addMonth(),
            'is_auto_payment_enabled' => true,
        ], $subscriptionOverrides));

        return [$admin, $subscription];
    }
}
