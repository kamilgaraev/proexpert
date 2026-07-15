<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildReferralSource();
        $this->dropLegacyForeignColumns();

        foreach (['organization_subscription_addons', 'subscription_addons', 'payments', 'user_subscriptions', 'organization_subscriptions', 'subscription_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        $this->restoreLegacyTables();

        if (Schema::hasTable('contractor_referral_rewards') && ! Schema::hasColumn('contractor_referral_rewards', 'invited_subscription_id')) {
            DB::table('contractor_referral_rewards')->delete();
            Schema::table('contractor_referral_rewards', function (Blueprint $table): void {
                $table->dropForeign(['commercial_payment_id']);
                $table->dropForeign(['commercial_order_id']);
                $table->dropUnique(['commercial_order_id']);
                $table->dropColumn(['commercial_payment_id', 'commercial_order_id']);
                $table->foreignId('invited_subscription_id')->nullable()->constrained('organization_subscriptions')->nullOnDelete();
            });
        }
    }

    private function rebuildReferralSource(): void
    {
        if (! Schema::hasTable('contractor_referral_rewards')) {
            return;
        }

        DB::table('contractor_referral_rewards')->delete();
        if (Schema::hasColumn('contractor_referral_rewards', 'invited_subscription_id')) {
            Schema::table('contractor_referral_rewards', function (Blueprint $table): void {
                $table->dropForeign(['invited_subscription_id']);
                $table->dropColumn('invited_subscription_id');
            });
        }
        if (! Schema::hasColumn('contractor_referral_rewards', 'commercial_order_id')) {
            Schema::table('contractor_referral_rewards', function (Blueprint $table): void {
                $table->foreignId('commercial_order_id')->unique()->constrained('commercial_orders')->cascadeOnDelete();
                $table->foreignId('commercial_payment_id')->constrained('commercial_payments')->cascadeOnDelete();
            });
        }
    }

    private function dropLegacyForeignColumns(): void
    {
        foreach (['payments', 'balance_transactions'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'organization_subscription_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropForeign(['organization_subscription_id']);
                    $table->dropColumn('organization_subscription_id');
                });
            }
        }

        if (Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'subscription_expires_at')) {
            Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('subscription_expires_at'));
        }

        if (! Schema::hasTable('organization_module_activations')) {
            return;
        }
        if (Schema::hasColumn('organization_module_activations', 'subscription_id')) {
            Schema::table('organization_module_activations', function (Blueprint $table): void {
                $table->dropForeign(['subscription_id']);
                $table->dropIndex(['subscription_id']);
                $table->dropColumn('subscription_id');
            });
        }
        $columns = array_values(array_filter(
            ['is_bundled_with_plan', 'paid_amount', 'payment_details', 'next_billing_date', 'is_auto_renew_enabled'],
            static fn (string $column): bool => Schema::hasColumn('organization_module_activations', $column),
        ));
        if ($columns !== []) {
            Schema::table('organization_module_activations', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    private function restoreLegacyTables(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->decimal('price', 12, 2)->default(0);
                $table->string('currency', 3)->default('RUB');
                $table->unsignedInteger('duration_in_days')->default(30);
                $table->boolean('is_active')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('organization_subscriptions')) {
            Schema::create('organization_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
                $table->string('status')->default('inactive');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
            });
        }
    }
};
