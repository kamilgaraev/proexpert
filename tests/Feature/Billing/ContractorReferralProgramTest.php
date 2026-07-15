<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\ContractorInvitation;
use App\Models\ContractorReferralReward;
use App\Models\Organization;
use App\Models\User;
use App\Services\Contractor\ContractorReferralRewardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ContractorReferralProgramTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public function test_first_authoritative_paid_purchase_creates_one_pending_reward(): void
    {
        [$order, $payment] = $this->scenario();
        $service = app(ContractorReferralRewardService::class);

        self::assertNotNull($service->handleFirstPaidOrder($order, $payment));
        self::assertNull($service->handleFirstPaidOrder($order, $payment));
        $this->assertDatabaseHas('contractor_referral_rewards', [
            'commercial_order_id' => $order->id,
            'commercial_payment_id' => $payment->id,
            'status' => 'pending',
            'eligible_at' => $order->period_end_at,
        ]);
    }

    public function test_bonus_is_accrued_only_after_paid_period_end(): void
    {
        [$order, $payment] = $this->scenario();
        $service = app(ContractorReferralRewardService::class);
        $service->handleFirstPaidOrder($order, $payment);

        self::assertSame(0, $service->accrueEligibleRewards($order->period_end_at->subSecond()));
        self::assertSame(1, $service->accrueEligibleRewards($order->period_end_at->addSecond()));
        self::assertSame(2, Schema::getConnection()->table('balance_transactions')->count());
    }

    public function test_refunded_order_never_accrues_bonus(): void
    {
        [$order, $payment] = $this->scenario();
        $service = app(ContractorReferralRewardService::class);
        $service->handleFirstPaidOrder($order, $payment);
        $order->forceFill(['status' => 'refunded'])->save();
        $payment->forceFill(['refunded_amount_minor' => 1])->save();

        self::assertSame(0, $service->accrueEligibleRewards($order->period_end_at->addSecond()));
        $this->assertDatabaseHas('contractor_referral_rewards', [
            'commercial_order_id' => $order->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'commercial_order_refunded',
        ]);
    }

    public function test_differently_formatted_equal_phones_cancel_referral(): void
    {
        [$order, $payment] = $this->scenario('+7 (999) 000-00-00', '79990000000');

        app(ContractorReferralRewardService::class)->handleFirstPaidOrder($order, $payment);

        $this->assertDatabaseHas('contractor_referral_rewards', [
            'commercial_order_id' => $order->id,
            'status' => ContractorReferralReward::STATUS_CANCELLED,
            'cancellation_reason' => 'same_phone',
        ]);
    }

    public function test_accrual_links_exact_bonus_transactions_and_is_idempotent(): void
    {
        [$order, $payment] = $this->scenario();
        $service = app(ContractorReferralRewardService::class);
        $reward = $service->handleFirstPaidOrder($order, $payment);

        self::assertNotNull($reward);
        self::assertSame(1, $service->accrueEligibleRewards($order->period_end_at->addSecond()));
        self::assertSame(0, $service->accrueEligibleRewards($order->period_end_at->addSeconds(2)));

        $reward->refresh();
        $invitedTransaction = Schema::getConnection()->table('balance_transactions')->find($reward->invited_balance_transaction_id);
        $invitingTransaction = Schema::getConnection()->table('balance_transactions')->find($reward->inviting_balance_transaction_id);

        self::assertSame('contractor_referral_welcome', json_decode($invitedTransaction->meta, true, flags: JSON_THROW_ON_ERROR)['type']);
        self::assertSame('contractor_referral_reward', json_decode($invitingTransaction->meta, true, flags: JSON_THROW_ON_ERROR)['type']);
        self::assertSame(2, Schema::getConnection()->table('balance_transactions')->count());
    }

    public function test_balance_credit_contract_locks_balance_and_returns_created_transaction(): void
    {
        $root = dirname(__DIR__, 3);
        $service = file_get_contents($root.'/app/Services/Billing/BalanceService.php');
        $contract = file_get_contents($root.'/app/Interfaces/Billing/BalanceServiceInterface.php');
        $referral = file_get_contents($root.'/app/Services/Contractor/ContractorReferralRewardService.php');

        self::assertIsString($service);
        self::assertIsString($contract);
        self::assertIsString($referral);
        self::assertStringContainsString('lockForUpdate()', $service);
        self::assertStringContainsString('): BalanceTransaction', $contract);
        self::assertStringContainsString("'invited_balance_transaction_id' => \$invitedTransaction->id", $referral);
        self::assertStringContainsString("'inviting_balance_transaction_id' => \$invitingTransaction->id", $referral);
        self::assertStringNotContainsString("latest('id')", $referral);
    }

    public function test_two_rewards_credit_same_inviter_balance_with_exact_distinct_transactions(): void
    {
        [$firstOrder, $firstPayment] = $this->scenario();
        $invitingOrganizationId = (int) ContractorInvitation::query()
            ->where('invited_organization_id', $firstOrder->organization_id)
            ->value('organization_id');
        [$secondOrder, $secondPayment] = $this->scenario();
        ContractorInvitation::query()
            ->where('invited_organization_id', $secondOrder->organization_id)
            ->update(['organization_id' => $invitingOrganizationId]);

        $service = app(ContractorReferralRewardService::class);
        self::assertNotNull($service->handleFirstPaidOrder($firstOrder, $firstPayment));
        self::assertNotNull($service->handleFirstPaidOrder($secondOrder, $secondPayment));
        self::assertSame(2, $service->accrueEligibleRewards($firstOrder->period_end_at->addSecond()));

        $rewards = ContractorReferralReward::query()->orderBy('id')->get();
        self::assertCount(2, $rewards);
        $transactionIds = $rewards->pluck('inviting_balance_transaction_id')->all();
        self::assertCount(2, array_unique($transactionIds));

        foreach ($rewards as $reward) {
            $transaction = Schema::getConnection()->table('balance_transactions')
                ->find($reward->inviting_balance_transaction_id);
            self::assertNotNull($transaction);
            self::assertSame(
                $reward->id,
                json_decode($transaction->meta, true, flags: JSON_THROW_ON_ERROR)['referral_reward_id'],
            );
        }

        $balance = Schema::getConnection()->table('organization_balances')
            ->where('organization_id', $invitingOrganizationId)
            ->sole();
        self::assertSame(1_200_000, $balance->balance);
        self::assertSame([600_000, 1_200_000], Schema::getConnection()->table('balance_transactions')
            ->where('organization_balance_id', $balance->id)
            ->orderBy('id')
            ->pluck('balance_after')
            ->all());
    }

    private function scenario(?string $invitingPhone = null, ?string $invitedPhone = null): array
    {
        $inviting = Organization::query()->create(['name' => 'Inviting', 'tax_number' => '1', 'email' => 'a@test', 'phone' => $invitingPhone]);
        $invited = Organization::query()->create(['name' => 'Invited', 'tax_number' => '2', 'email' => 'b@test', 'phone' => $invitedPhone]);
        $user = User::query()->create(['name' => 'Inviter', 'email' => 'u@test', 'password' => 'secret']);
        ContractorInvitation::query()->create([
            'organization_id' => $inviting->id,
            'invited_organization_id' => $invited->id,
            'invited_by_user_id' => $user->id,
            'status' => ContractorInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $order = CommercialOrder::query()->create([
            'public_id' => 'order-1', 'organization_id' => $invited->id, 'commercial_account_id' => 1,
            'status' => 'paid', 'offer_type' => 'packages', 'quote_version' => 1,
            'selected_package_slugs' => ['projects-processes'], 'current_package_slugs' => [],
            'amount_minor' => 2_000_000, 'amount' => 20000, 'currency' => 'RUB',
            'period_start_at' => CarbonImmutable::parse('2026-07-01'),
            'period_end_at' => CarbonImmutable::parse('2026-08-01'),
            'client_idempotency_key' => 'client-1', 'server_idempotency_key' => 'server-1', 'kind' => 'purchase',
        ]);
        $payment = CommercialPayment::query()->create([
            'commercial_order_id' => $order->id, 'provider' => 'yookassa', 'provider_payment_id' => 'payment-1',
            'provider_status' => 'succeeded', 'amount_minor' => 2_000_000, 'currency' => 'RUB',
            'provider_idempotency_key' => 'provider-1', 'refunded_amount_minor' => 0, 'role' => 'initial', 'attempt_number' => 1,
        ]);

        return [$order, $payment];
    }

    private function createSchema(): void
    {
        foreach (['contractor_referral_rewards', 'balance_transactions', 'organization_balances', 'contractor_invitations', 'commercial_payments', 'commercial_orders', 'users', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tax_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->foreignId('current_organization_id')->nullable();
            $table->timestamps();
        });
        Schema::create('commercial_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id');
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->foreignId('user_id')->nullable();
            $table->string('status');
            $table->string('offer_type');
            $table->integer('quote_version');
            $table->json('selected_package_slugs');
            $table->json('current_package_slugs');
            $table->bigInteger('amount_minor');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('period_start_at');
            $table->timestamp('period_end_at');
            $table->boolean('auto_renew_consent')->default(false);
            $table->string('client_idempotency_key');
            $table->string('server_idempotency_key');
            $table->string('kind');
            $table->timestamps();
        });
        Schema::create('commercial_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id');
            $table->string('provider');
            $table->string('provider_payment_id');
            $table->string('provider_status');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('provider_idempotency_key');
            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->string('role');
            $table->integer('attempt_number');
            $table->boolean('payment_method_saved')->default(false);
            $table->boolean('reconciliation_required')->default(false);
            $table->timestamps();
        });
        Schema::create('contractor_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('invited_organization_id');
            $table->foreignId('invited_by_user_id');
            $table->string('token')->nullable();
            $table->string('status');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
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
            $table->foreignId('commercial_order_id')->unique();
            $table->foreignId('commercial_payment_id');
            $table->foreignId('inviting_balance_transaction_id')->nullable();
            $table->foreignId('invited_balance_transaction_id')->nullable();
            $table->string('status');
            $table->bigInteger('first_payment_amount');
            $table->bigInteger('inviting_reward_amount');
            $table->bigInteger('invited_welcome_amount');
            $table->string('currency', 3);
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
