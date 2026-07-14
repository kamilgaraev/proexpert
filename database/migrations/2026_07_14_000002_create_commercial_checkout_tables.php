<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->timestampsTz();

            $table->unique(['organization_id', 'client_idempotency_key'], 'commercial_orders_org_client_key_unique');
            $table->unique(['id', 'organization_id'], 'commercial_orders_id_org_unique');
            $table->foreign(['commercial_account_id', 'organization_id'], 'commercial_orders_account_tenant_fk')
                ->references(['id', 'organization_id'])
                ->on('organization_commercial_accounts')
                ->restrictOnDelete();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('commercial_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id')->unique()->constrained('commercial_orders')->cascadeOnDelete();
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
            $table->timestampsTz();

            $table->index(['provider', 'provider_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_payments');
        Schema::dropIfExists('commercial_orders');
    }
};
