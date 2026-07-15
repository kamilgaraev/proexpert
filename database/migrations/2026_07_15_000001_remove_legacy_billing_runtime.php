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
        $this->preserveReferralAudit();
        $this->dropLegacyForeignColumns();

        foreach (['organization_subscription_addons', 'subscription_addons', 'payments', 'user_subscriptions', 'organization_subscriptions', 'subscription_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'The legacy billing cleanup is irreversible. Restore the database backup before deploying the previous application version.'
        );
    }

    private function preserveReferralAudit(): void
    {
        if (! Schema::hasTable('contractor_referral_rewards')) {
            return;
        }

        if (Schema::hasColumn('contractor_referral_rewards', 'invited_subscription_id')) {
            Schema::table('contractor_referral_rewards', function (Blueprint $table): void {
                $table->dropForeign(['invited_subscription_id']);
            });
            Schema::table('contractor_referral_rewards', function (Blueprint $table): void {
                $table->unsignedBigInteger('invited_subscription_id')->nullable()->change();
            });
            Schema::table('contractor_referral_rewards', function (Blueprint $table): void {
                $table->renameColumn('invited_subscription_id', 'legacy_invited_subscription_id');
            });
        }

        if (! Schema::hasColumn('contractor_referral_rewards', 'commercial_order_id')) {
            Schema::table('contractor_referral_rewards', function (Blueprint $table): void {
                $table->foreignId('commercial_order_id')
                    ->nullable()
                    ->unique()
                    ->constrained('commercial_orders')
                    ->nullOnDelete();
                $table->foreignId('commercial_payment_id')
                    ->nullable()
                    ->constrained('commercial_payments')
                    ->nullOnDelete();
            });
        }

        DB::table('contractor_referral_rewards')
            ->where('status', 'pending')
            ->whereNull('commercial_order_id')
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'commercial_source_not_found_after_billing_migration',
                'updated_at' => now(),
            ]);
    }

    private function dropLegacyForeignColumns(): void
    {
        foreach (['payments', 'balance_transactions'] as $tableName) {
            foreach (['organization_subscription_id', 'user_subscription_id'] as $column) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, function (Blueprint $table) use ($column): void {
                        $table->dropForeign([$column]);
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (Schema::hasTable('balance_transactions') && Schema::hasColumn('balance_transactions', 'payment_id')) {
            Schema::table('balance_transactions', function (Blueprint $table): void {
                $table->dropForeign(['payment_id']);
                $table->dropColumn('payment_id');
            });
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
};
