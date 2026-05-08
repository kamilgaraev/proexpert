<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contractor_invitation_id')->constrained('contractor_invitations')->cascadeOnDelete();
            $table->foreignId('inviting_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('invited_organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('invited_subscription_id')->constrained('organization_subscriptions')->cascadeOnDelete();
            $table->foreignId('inviting_balance_transaction_id')->nullable()->constrained('balance_transactions')->nullOnDelete();
            $table->foreignId('invited_balance_transaction_id')->nullable()->constrained('balance_transactions')->nullOnDelete();
            $table->string('status', 30)->default('pending');
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

            $table->index(['status', 'eligible_at']);
            $table->index(['inviting_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_referral_rewards');
    }
};
