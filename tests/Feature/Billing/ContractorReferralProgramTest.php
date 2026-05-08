<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\BalanceTransaction;
use App\Models\ContractorInvitation;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\BalanceService;
use App\Services\Contractor\ContractorReferralRewardService;
use App\Services\Landing\OrganizationSubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ContractorReferralProgramTest extends TestCase
{
    private Organization $invitingOrganization;
    private Organization $invitedOrganization;
    private User $invitingUser;

    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        $this->invitingOrganization = Organization::query()->create([
            'name' => 'Inviting contractor',
            'tax_number' => '7701000001',
            'email' => 'owner@inviting.test',
            'phone' => '+79990000001',
            'is_active' => true,
        ]);

        $this->invitedOrganization = Organization::query()->create([
            'name' => 'Invited contractor',
            'tax_number' => '7701000002',
            'email' => 'owner@invited.test',
            'phone' => '+79990000002',
            'is_active' => true,
        ]);

        $this->invitingUser = User::query()->create([
            'name' => 'Inviter',
            'email' => 'inviter@example.test',
            'password' => 'secret',
            'current_organization_id' => $this->invitingOrganization->id,
        ]);

        SubscriptionPlan::query()->create([
            'name' => 'Business',
            'slug' => 'business',
            'description' => 'Business plan',
            'price' => 19900,
            'currency' => 'RUB',
            'duration_in_days' => 30,
            'features' => [],
            'included_packages' => [],
            'is_active' => true,
        ]);

        app(BalanceService::class)->creditBalance(
            $this->invitedOrganization,
            3_000_000,
            'Test balance'
        );
    }

    public function test_first_paid_subscription_creates_pending_referral_without_immediate_bonus(): void
    {
        $this->createAcceptedInvitation();

        $subscription = app(OrganizationSubscriptionService::class)
            ->subscribe($this->invitedOrganization->id, 'business', false, 30);

        $this->assertDatabaseHas('contractor_referral_rewards', [
            'contractor_invitation_id' => ContractorInvitation::query()->value('id'),
            'inviting_organization_id' => $this->invitingOrganization->id,
            'invited_organization_id' => $this->invitedOrganization->id,
            'invited_subscription_id' => $subscription->id,
            'status' => 'pending',
            'inviting_reward_amount' => 600_000,
            'invited_welcome_amount' => 400_000,
        ]);

        $this->assertSame(0, BalanceTransaction::query()
            ->where('type', BalanceTransaction::TYPE_CREDIT)
            ->whereIn('amount', [400_000, 600_000])
            ->whereIn('meta->type', ['contractor_referral_welcome', 'contractor_referral_reward'])
            ->count());
    }

    public function test_referral_bonuses_are_accrued_after_first_subscription_period_ends(): void
    {
        $this->createAcceptedInvitation();

        $subscription = app(OrganizationSubscriptionService::class)
            ->subscribe($this->invitedOrganization->id, 'business', false, 30);

        Carbon::setTestNow($subscription->ends_at->copy()->subSecond());
        $this->assertSame(0, app(ContractorReferralRewardService::class)->accrueEligibleRewards());

        Carbon::setTestNow($subscription->ends_at->copy()->addSecond());
        $this->assertSame(1, app(ContractorReferralRewardService::class)->accrueEligibleRewards());

        $this->assertDatabaseHas('contractor_referral_rewards', [
            'invited_subscription_id' => $subscription->id,
            'status' => 'accrued',
            'inviting_reward_amount' => 600_000,
            'invited_welcome_amount' => 400_000,
        ]);

        $this->assertSame(1, BalanceTransaction::query()
            ->where('type', BalanceTransaction::TYPE_CREDIT)
            ->where('amount', 600_000)
            ->where('meta->type', 'contractor_referral_reward')
            ->count());

        $this->assertSame(1, BalanceTransaction::query()
            ->where('type', BalanceTransaction::TYPE_CREDIT)
            ->where('amount', 400_000)
            ->where('meta->type', 'contractor_referral_welcome')
            ->count());
    }

    public function test_referral_is_cancelled_when_organizations_share_tax_number(): void
    {
        $this->invitedOrganization->update(['tax_number' => $this->invitingOrganization->tax_number]);
        $this->createAcceptedInvitation();

        app(OrganizationSubscriptionService::class)
            ->subscribe($this->invitedOrganization->id, 'business', false, 30);

        $this->assertDatabaseHas('contractor_referral_rewards', [
            'inviting_organization_id' => $this->invitingOrganization->id,
            'invited_organization_id' => $this->invitedOrganization->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'same_tax_number',
        ]);

        $this->assertSame(0, BalanceTransaction::query()
            ->where('type', BalanceTransaction::TYPE_CREDIT)
            ->whereIn('amount', [400_000, 600_000])
            ->whereIn('meta->type', ['contractor_referral_welcome', 'contractor_referral_reward'])
            ->count());
    }

    public function test_inviting_reward_is_cancelled_if_subscription_is_cancelled_before_period_end(): void
    {
        $this->createAcceptedInvitation();

        $subscription = app(OrganizationSubscriptionService::class)
            ->subscribe($this->invitedOrganization->id, 'business', false, 30);

        $subscription->update(['canceled_at' => $subscription->ends_at->copy()->subDay()]);

        Carbon::setTestNow($subscription->ends_at->copy()->addSecond());
        $this->assertSame(0, app(ContractorReferralRewardService::class)->accrueEligibleRewards());

        $this->assertDatabaseHas('contractor_referral_rewards', [
            'invited_subscription_id' => $subscription->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'subscription_cancelled_before_period_end',
        ]);

        $this->assertSame(0, BalanceTransaction::query()
            ->where('type', BalanceTransaction::TYPE_CREDIT)
            ->whereIn('amount', [400_000, 600_000])
            ->whereIn('meta->type', ['contractor_referral_welcome', 'contractor_referral_reward'])
            ->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createAcceptedInvitation(): ContractorInvitation
    {
        return ContractorInvitation::query()->create([
            'organization_id' => $this->invitingOrganization->id,
            'invited_organization_id' => $this->invitedOrganization->id,
            'invited_by_user_id' => $this->invitingUser->id,
            'status' => ContractorInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('contractor_referral_rewards');
        Schema::dropIfExists('balance_transactions');
        Schema::dropIfExists('organization_balances');
        Schema::dropIfExists('contractor_invitations');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tax_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('current_organization_id')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->integer('duration_in_days')->default(30);
            $table->integer('max_foremen')->nullable();
            $table->integer('max_projects')->nullable();
            $table->integer('max_storage_gb')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_contractor_invitations')->nullable();
            $table->json('features')->nullable();
            $table->json('included_packages')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('organization_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('subscription_plan_id');
            $table->string('status')->default('active');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('payment_failure_notified_at')->nullable();
            $table->string('payment_gateway_subscription_id')->nullable();
            $table->string('payment_gateway_customer_id')->nullable();
            $table->boolean('is_auto_payment_enabled')->default(true);
            $table->json('enterprise_constructor_config')->nullable();
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->json('pricing_config')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_module_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('module_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('package_slug');
            $table->string('tier');
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contractor_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('invited_organization_id');
            $table->foreignId('invited_by_user_id');
            $table->string('token', 64)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable();
            $table->text('invitation_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique();
            $table->bigInteger('balance')->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->timestamps();
        });

        Schema::create('balance_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_balance_id');
            $table->foreignId('payment_id')->nullable();
            $table->foreignId('organization_subscription_id')->nullable();
            $table->string('type');
            $table->bigInteger('amount');
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('contractor_referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contractor_invitation_id');
            $table->foreignId('inviting_organization_id');
            $table->foreignId('invited_organization_id')->unique();
            $table->foreignId('invited_subscription_id');
            $table->foreignId('inviting_balance_transaction_id')->nullable();
            $table->foreignId('invited_balance_transaction_id')->nullable();
            $table->string('status')->default('pending');
            $table->bigInteger('first_payment_amount');
            $table->bigInteger('inviting_reward_amount');
            $table->bigInteger('invited_welcome_amount');
            $table->string('currency', 3)->default('RUB');
            $table->timestamp('eligible_at');
            $table->timestamp('invited_welcome_accrued_at')->nullable();
            $table->timestamp('accrued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
}
