<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationPackageTrialUsage;
use App\Models\User;
use App\Services\Billing\CommercialBillingNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CommercialBillingNotificationServiceTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_notifications_are_idempotent_in_app_only_and_trial_expiry_preserves_ledger(): void
    {
        $this->schema();
        $now = CarbonImmutable::parse('2026-07-14 03:00:00', 'Europe/Moscow');
        $user = User::query()->create(['name' => 'Owner', 'email' => 'notify@example.test', 'password' => 'password', 'is_active' => true]);
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => 1, 'responsible_user_id' => $user->id, 'status' => 'active',
            'offer_type' => 'packages', 'quote_version' => 1, 'current_period_end_at' => $now->addDays(3),
            'auto_renew_enabled' => true, 'saved_payment_method_active' => true,
        ]);
        $trial = OrganizationPackageSubscription::query()->create([
            'organization_id' => 1, 'commercial_account_id' => $account->id, 'package_slug' => 'machinery',
            'status' => 'trialing', 'access_source' => 'trial', 'price_paid' => 0,
            'trial_started_at' => $now->subDays(2), 'trial_ends_at' => $now->addDay(),
        ]);
        OrganizationPackageTrialUsage::query()->create([
            'organization_id' => 1, 'package_slug' => 'machinery',
            'started_at' => $now->subDays(2), 'ends_at' => $now->addDay(),
        ]);
        $service = app(CommercialBillingNotificationService::class);

        $service->process($now);
        $service->process($now);
        $service->process($now->addDay()->addSecond());
        $service->process($now->addDay()->addSecond());
        $service->process($now->addDays(2));
        $account->forceFill(['status' => 'grace', 'grace_started_at' => $now->addDays(3), 'grace_ends_at' => $now->addDays(10)])->save();
        $service->process($now->addDays(4));
        $service->process($now->addDays(4));
        $account->forceFill(['status' => 'suspended'])->save();
        $service->process($now->addDays(10));
        $service->process($now->addDays(10));

        $this->assertSame('expired', $trial->fresh()->status->value);
        $this->assertSame(1, OrganizationPackageTrialUsage::query()->count());
        $this->assertSame(6, Notification::query()->count());
        $this->assertSame(6, Notification::query()->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->count());
        foreach (Notification::query()->get() as $notification) {
            $this->assertSame(['in_app'], $notification->channels);
            $this->assertSame('billing', $notification->notification_type);
            $this->assertSame([], $notification->delivery_status);
            $this->assertArrayNotHasKey('payment_method_id', $notification->data);
        }
        $this->assertDatabaseCount('commercial_renewal_cycles', 0);
        $this->assertDatabaseCount('commercial_payments', 0);
        $this->assertSame('suspended', $account->fresh()->status->value);
    }

    public function test_upcoming_notifications_use_moscow_calendar_dates_for_utc_previous_day(): void
    {
        $this->schema();
        $user = User::query()->create(['name' => 'Owner', 'email' => 'calendar@example.test', 'password' => 'password', 'is_active' => true]);
        $due = CarbonImmutable::parse('2026-07-17 00:30:00', 'Europe/Moscow');
        OrganizationCommercialAccount::query()->create([
            'organization_id' => 1, 'responsible_user_id' => $user->id, 'status' => 'active',
            'offer_type' => 'packages', 'quote_version' => 1, 'current_period_end_at' => $due->utc(),
            'auto_renew_enabled' => true, 'saved_payment_method_active' => true,
        ]);
        $service = app(CommercialBillingNotificationService::class);

        $service->processRenewalLifecycle(CarbonImmutable::parse('2026-07-13 03:00', 'Europe/Moscow'));
        $this->assertSame(0, Notification::query()->count());
        $service->processRenewalLifecycle(CarbonImmutable::parse('2026-07-14 03:00', 'Europe/Moscow'));
        $service->processRenewalLifecycle(CarbonImmutable::parse('2026-07-14 20:00', 'Europe/Moscow'));
        $this->assertSame(1, Notification::query()->count());
        $service->processRenewalLifecycle(CarbonImmutable::parse('2026-07-15 03:00', 'Europe/Moscow'));
        $this->assertSame(1, Notification::query()->count());
        $service->processRenewalLifecycle(CarbonImmutable::parse('2026-07-16 03:00', 'Europe/Moscow'));
        $service->processRenewalLifecycle(CarbonImmutable::parse('2026-07-16 20:00', 'Europe/Moscow'));
        $this->assertSame(2, Notification::query()->count());
    }

    private function schema(): void
    {
        foreach (['notifications', 'commercial_billing_notification_keys', 'commercial_payments', 'commercial_renewal_cycles', 'organization_package_trial_usages', 'organization_package_subscriptions', 'organization_commercial_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->boolean('is_active');
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('organization_commercial_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id');
            $t->foreignId('responsible_user_id')->nullable();
            $t->string('status');
            $t->string('offer_type');
            $t->unsignedInteger('quote_version');
            $t->timestamp('billing_anchor_at')->nullable();
            $t->timestamp('current_period_start_at')->nullable();
            $t->timestamp('current_period_end_at')->nullable();
            $t->boolean('auto_renew_enabled');
            $t->string('saved_payment_method_id')->nullable();
            $t->boolean('saved_payment_method_active');
            $t->timestamp('grace_started_at')->nullable();
            $t->timestamp('grace_ends_at')->nullable();
            $t->timestamps();
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
            $t->timestamps();
        });
        Schema::create('organization_package_trial_usages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id');
            $t->string('package_slug');
            $t->timestamp('started_at');
            $t->timestamp('ends_at');
            $t->timestamps();
        });
        Schema::create('commercial_renewal_cycles', function (Blueprint $t): void {
            $t->id();
        });
        Schema::create('commercial_payments', function (Blueprint $t): void {
            $t->id();
        });
        Schema::create('commercial_billing_notification_keys', function (Blueprint $t): void {
            $t->string('idempotency_key')->primary();
            $t->foreignId('organization_id');
            $t->foreignId('commercial_account_id');
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('notifiable_type');
            $t->unsignedBigInteger('notifiable_id');
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('notification_type');
            $t->string('priority');
            $t->json('channels');
            $t->json('delivery_status');
            $t->json('data');
            $t->json('metadata')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }
}
