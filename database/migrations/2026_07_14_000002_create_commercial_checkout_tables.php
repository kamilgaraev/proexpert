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
        Schema::create('commercial_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('commercial_account_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('kind', ['purchase', 'renewal'])->default('purchase');
            $table->enum('status', ['draft', 'pending_payment', 'paid', 'canceled', 'refunded']);
            $table->enum('offer_type', ['packages', 'full_suite']);
            $table->unsignedInteger('quote_version');
            $table->jsonb('selected_package_slugs');
            $table->jsonb('current_package_slugs');
            $table->unsignedBigInteger('amount_minor');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->timestampTz('period_start_at');
            $table->timestampTz('period_end_at');
            $table->boolean('auto_renew_consent')->default(false);
            $table->string('client_idempotency_key', 100);
            $table->string('server_idempotency_key', 150)->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'client_idempotency_key'], 'commercial_orders_org_client_key_unique');
            $table->unique(['organization_id', 'server_idempotency_key'], 'commercial_orders_org_server_key_unique');
            $table->unique(['id', 'organization_id'], 'commercial_orders_id_org_unique');
            $table->unique(['id', 'commercial_account_id', 'organization_id'], 'commercial_orders_id_account_org_unique');
            $table->foreign(['commercial_account_id', 'organization_id'], 'commercial_orders_account_tenant_fk')
                ->references(['id', 'organization_id'])
                ->on('organization_commercial_accounts')
                ->restrictOnDelete();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('commercial_renewal_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('commercial_account_id');
            $table->foreignId('commercial_order_id');
            $table->enum('status', ['due', 'grace', 'paid', 'suspended', 'disabled', 'manual_review'])->default('due');
            $table->timestampTz('due_at');
            $table->date('billing_due_date');
            $table->timestampTz('target_period_start_at');
            $table->timestampTz('target_period_end_at');
            $table->timestampTz('grace_deadline_at');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('manual_review_at')->nullable();
            $table->timestampsTz();

            $table->unique(['commercial_account_id', 'target_period_start_at'], 'commercial_renewal_account_period_unique');
            $table->unique('commercial_order_id', 'commercial_renewal_order_unique');
            $table->unique(['id', 'commercial_order_id'], 'commercial_renewal_cycle_order_unique');
            $table->foreign(['commercial_account_id', 'organization_id'], 'commercial_renewal_account_tenant_fk')
                ->references(['id', 'organization_id'])->on('organization_commercial_accounts')->restrictOnDelete();
            $table->foreign(['commercial_order_id', 'organization_id'], 'commercial_renewal_order_tenant_fk')
                ->references(['id', 'organization_id'])->on('commercial_orders')->restrictOnDelete();
            $table->foreign(['commercial_order_id', 'commercial_account_id', 'organization_id'], 'commercial_renewal_order_account_tenant_fk')
                ->references(['id', 'commercial_account_id', 'organization_id'])->on('commercial_orders')->restrictOnDelete();
            $table->index(['status', 'next_attempt_at', 'id'], 'commercial_renewal_due_idx');
        });

        Schema::create('commercial_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id')->constrained('commercial_orders')->cascadeOnDelete();
            $table->foreignId('commercial_renewal_cycle_id')->nullable();
            $table->enum('role', ['initial', 'renewal'])->default('initial');
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('provider', 30)->default('yookassa');
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('provider_status', 50)->default('created');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->uuid('provider_idempotency_key')->unique();
            $table->text('confirmation_url')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->boolean('payment_method_saved')->default(false);
            $table->jsonb('safe_response')->nullable();
            $table->string('terminal_failure_reason', 80)->nullable();
            $table->enum('failure_category', ['retryable', 'method_revoked', 'non_retryable'])->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('terminal_at')->nullable();
            $table->timestampsTz();

            $table->index(['provider', 'provider_status']);
            $table->unique(['commercial_order_id', 'attempt_number'], 'commercial_payment_order_attempt_unique');
            $table->foreign(['commercial_renewal_cycle_id', 'commercial_order_id'], 'commercial_payment_cycle_order_fk')
                ->references(['id', 'commercial_order_id'])->on('commercial_renewal_cycles')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE commercial_payments ADD CONSTRAINT commercial_payment_role_cycle_check CHECK ((role = 'initial' AND commercial_renewal_cycle_id IS NULL) OR (role = 'renewal' AND commercial_renewal_cycle_id IS NOT NULL))");

        Schema::create('commercial_billing_notification_keys', function (Blueprint $table): void {
            $table->char('idempotency_key', 64)->primary();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->timestampsTz();
            $table->foreign(['commercial_account_id', 'organization_id'], 'commercial_notification_account_tenant_fk')
                ->references(['id', 'organization_id'])->on('organization_commercial_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_billing_notification_keys');
        Schema::dropIfExists('commercial_payments');
        Schema::dropIfExists('commercial_renewal_cycles');
        Schema::dropIfExists('commercial_orders');
    }
};
