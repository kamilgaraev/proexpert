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
        DB::statement('ALTER TABLE commercial_orders DROP CONSTRAINT IF EXISTS commercial_orders_kind_check');
        DB::statement("ALTER TABLE commercial_orders ADD CONSTRAINT commercial_orders_kind_check CHECK (kind IN ('purchase', 'renewal', 'ai_credits'))");

        Schema::create('ai_credit_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->unsignedBigInteger('reserved_minor')->default(0);
            $table->timestampsTz();
        });

        Schema::create('ai_credit_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('source', 30);
            $table->unsignedBigInteger('original_minor');
            $table->unsignedBigInteger('remaining_minor');
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('commercial_order_id')->nullable()->constrained('commercial_orders')->nullOnDelete();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->index(['organization_id', 'expires_at', 'id'], 'ai_credit_lots_expiry_idx');
        });

        Schema::create('ai_credit_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->char('request_hash', 64);
            $table->string('profile', 40);
            $table->unsignedInteger('price_version');
            $table->jsonb('limits');
            $table->jsonb('pricing');
            $table->unsignedBigInteger('min_units_minor');
            $table->unsignedBigInteger('max_units_minor');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['organization_id', 'user_id', 'request_key'], 'ai_credit_quotes_request_idx');
            $table->index(['organization_id', 'expires_at']);
        });

        Schema::create('ai_credit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ai_credit_quote_id')->constrained('ai_credit_quotes')->restrictOnDelete();
            $table->string('request_id', 100);
            $table->string('conversation_id', 100)->nullable();
            $table->unsignedBigInteger('reserved_minor');
            $table->unsignedBigInteger('consumed_minor')->default(0);
            $table->string('status', 20)->default('reserved');
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'request_id'], 'ai_credit_reservations_request_unique');
        });

        Schema::create('ai_credit_reservation_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_credit_reservation_id')->constrained('ai_credit_reservations')->cascadeOnDelete();
            $table->foreignId('ai_credit_lot_id')->constrained('ai_credit_lots')->restrictOnDelete();
            $table->unsignedBigInteger('reserved_minor');
            $table->unsignedBigInteger('consumed_minor')->default(0);
            $table->timestampsTz();
            $table->unique(['ai_credit_reservation_id', 'ai_credit_lot_id'], 'ai_credit_reservation_lot_unique');
        });

        Schema::create('ai_credit_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('idempotency_key', 150);
            $table->unique(['organization_id', 'idempotency_key'], 'ai_credit_ledger_idempotency_unique');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->index(['organization_id', 'id']);
        });

        Schema::create('ai_credit_provider_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('ai_credit_reservation_id')->nullable()->constrained('ai_credit_reservations')->nullOnDelete();
            $table->string('usage_key', 150)->nullable();
            $table->unique(['ai_credit_reservation_id', 'usage_key'], 'ai_credit_usage_idempotency_unique');
            $table->string('provider', 50);
            $table->string('model', 150);
            $table->string('operation', 50);
            $table->unsignedBigInteger('cost_micro_rub');
            $table->boolean('is_successful')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['organization_id', 'occurred_at']);
        });

        DB::statement('ALTER TABLE ai_credit_wallets ADD CONSTRAINT ai_credit_wallets_nonnegative_check CHECK (balance_minor >= 0 AND reserved_minor >= 0)');
        DB::statement('ALTER TABLE ai_credit_lots ADD CONSTRAINT ai_credit_lots_nonnegative_check CHECK (original_minor >= 0 AND remaining_minor >= 0)');
        DB::statement("ALTER TABLE ai_credit_lots ADD CONSTRAINT ai_credit_purchases_never_expire CHECK (source <> 'purchase' OR expires_at IS NULL)");
        DB::statement('ALTER TABLE ai_credit_reservations ADD CONSTRAINT ai_credit_reservations_nonnegative_check CHECK (reserved_minor >= 0 AND consumed_minor >= 0)');
        DB::statement('ALTER TABLE ai_credit_provider_usages ADD CONSTRAINT ai_credit_usages_nonnegative_check CHECK (cost_micro_rub >= 0)');
        DB::statement('ALTER TABLE ai_credit_wallets ADD CONSTRAINT ai_credit_wallets_reserved_check CHECK (reserved_minor <= balance_minor)');
        DB::statement('ALTER TABLE ai_credit_lots ADD CONSTRAINT ai_credit_lots_remaining_check CHECK (remaining_minor <= original_minor)');
        DB::statement('ALTER TABLE ai_credit_quotes ADD CONSTRAINT ai_credit_quotes_bounds_check CHECK (min_units_minor <= max_units_minor)');
        DB::statement("ALTER TABLE ai_credit_reservations ADD CONSTRAINT ai_credit_reservations_status_check CHECK (status IN ('reserved', 'finalized', 'cancelled'))");
        DB::statement('ALTER TABLE ai_credit_reservations ADD CONSTRAINT ai_credit_reservations_consumed_check CHECK (consumed_minor <= reserved_minor)');
        DB::statement('ALTER TABLE ai_credit_reservation_allocations ADD CONSTRAINT ai_credit_reservation_allocations_consumed_check CHECK (consumed_minor <= reserved_minor)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_provider_usages');
        Schema::dropIfExists('ai_credit_ledger_entries');
        Schema::dropIfExists('ai_credit_reservation_allocations');
        Schema::dropIfExists('ai_credit_reservations');
        Schema::dropIfExists('ai_credit_quotes');
        Schema::dropIfExists('ai_credit_lots');
        Schema::dropIfExists('ai_credit_wallets');
    }
};
