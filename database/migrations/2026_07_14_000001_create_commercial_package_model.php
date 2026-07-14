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
        Schema::create('organization_commercial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->enum('status', ['free', 'active', 'grace', 'suspended', 'corporate'])->default('free');
            $table->enum('offer_type', ['packages', 'full_suite', 'corporate'])->default('packages');
            $table->unsignedInteger('quote_version');
            $table->timestampTz('billing_anchor_at')->nullable();
            $table->timestampTz('current_period_start_at')->nullable();
            $table->timestampTz('current_period_end_at')->nullable();
            $table->boolean('auto_renew_enabled')->default(true);
            $table->timestampsTz();
            $table->unique(['id', 'organization_id'], 'commercial_accounts_id_org_unique');
        });

        DB::table('organization_package_subscriptions')->delete();

        Schema::table('organization_package_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['subscription_id']);
            $table->dropUnique('org_package_source_unique');
            $table->dropIndex('org_package_subscription_idx');
            $table->dropIndex('org_package_bundled_idx');
            $table->dropIndex(['organization_id', 'expires_at']);
            $table->dropColumn([
                'subscription_id',
                'is_bundled_with_plan',
                'tier',
                'activated_at',
                'expires_at',
            ]);
        });

        Schema::table('organization_package_subscriptions', function (Blueprint $table): void {
            $table->foreignId('commercial_account_id')
                ->after('organization_id');
            $table->foreign(['commercial_account_id', 'organization_id'], 'org_package_account_tenant_fk')
                ->references(['id', 'organization_id'])
                ->on('organization_commercial_accounts')
                ->cascadeOnDelete();
            $table->enum('status', [
                'trialing',
                'active',
                'grace',
                'scheduled_for_removal',
                'expired',
                'canceled',
            ])->after('package_slug');
            $table->enum('access_source', [
                'trial',
                'paid_package',
                'full_suite',
                'corporate',
            ])->after('status');
            $table->timestampTz('current_period_start_at')->nullable();
            $table->timestampTz('current_period_end_at')->nullable();
            $table->timestampTz('trial_started_at')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('cancel_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();

            $table->unique(['organization_id', 'package_slug']);
            $table->index(['organization_id', 'status', 'current_period_end_at'], 'org_package_access_idx');
        });

        Schema::create('organization_package_trial_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('package_slug', 100);
            $table->timestampTz('started_at');
            $table->timestampTz('ends_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'package_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_package_trial_usages');

        DB::table('organization_package_subscriptions')->delete();

        Schema::table('organization_package_subscriptions', function (Blueprint $table): void {
            $table->dropForeign('org_package_account_tenant_fk');
            $table->dropUnique(['organization_id', 'package_slug']);
            $table->dropIndex('org_package_access_idx');
            $table->dropColumn([
                'commercial_account_id',
                'status',
                'access_source',
                'current_period_start_at',
                'current_period_end_at',
                'trial_started_at',
                'trial_ends_at',
                'cancel_at',
                'canceled_at',
            ]);
        });

        Schema::table('organization_package_subscriptions', function (Blueprint $table): void {
            $table->foreignId('subscription_id')->nullable()->constrained('organization_subscriptions')->nullOnDelete();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('tier', 50);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unique(
                ['organization_id', 'package_slug', 'is_bundled_with_plan'],
                'org_package_source_unique',
            );
            $table->index(['organization_id', 'is_bundled_with_plan'], 'org_package_bundled_idx');
            $table->index('subscription_id', 'org_package_subscription_idx');
            $table->index(['organization_id', 'expires_at']);
        });

        Schema::dropIfExists('organization_commercial_accounts');
    }
};
