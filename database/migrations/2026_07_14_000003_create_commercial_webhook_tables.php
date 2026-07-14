<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->unique(['id', 'commercial_order_id'], 'commercial_payments_id_order_unique');
        });

        Schema::table('organization_package_subscriptions', function (Blueprint $table): void {
            $table->foreignId('source_order_id')->nullable();
            $table->foreign(['source_order_id', 'organization_id'], 'org_package_source_order_tenant_fk')
                ->references(['id', 'organization_id'])
                ->on('commercial_orders')
                ->nullOnDelete();
            $table->index('source_order_id', 'org_package_source_order_idx');
        });

        Schema::create('commercial_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id')->constrained('commercial_orders')->cascadeOnDelete();
            $table->foreignId('commercial_payment_id')->constrained('commercial_payments')->cascadeOnDelete();
            $table->foreign(['commercial_payment_id', 'commercial_order_id'], 'commercial_refunds_payment_order_fk')
                ->references(['id', 'commercial_order_id'])
                ->on('commercial_payments')
                ->cascadeOnDelete();
            $table->string('provider', 30)->default('yookassa');
            $table->string('provider_refund_id')->unique();
            $table->string('provider_status', 50);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->jsonb('safe_response')->nullable();
            $table->timestampsTz();
        });

        Schema::create('commercial_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_name', 80);
            $table->string('object_id');
            $table->string('authoritative_status', 50)->nullable();
            $table->string('processing_result', 50);
            $table->string('source_ip', 45);
            $table->char('fingerprint', 64);
            $table->jsonb('safe_payload')->nullable();
            $table->timestampTz('processed_at');
            $table->timestampsTz();
            $table->unique('fingerprint', 'commercial_webhook_events_fingerprint_unique');
            $table->index(['provider', 'event_name', 'object_id'], 'commercial_webhook_events_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_webhook_events');
        Schema::dropIfExists('commercial_refunds');

        Schema::table('organization_package_subscriptions', function (Blueprint $table): void {
            $table->dropForeign('org_package_source_order_tenant_fk');
            $table->dropIndex('org_package_source_order_idx');
            $table->dropColumn('source_order_id');
        });

        Schema::table('commercial_payments', function (Blueprint $table): void {
            $table->dropUnique('commercial_payments_id_order_unique');
            $table->dropColumn('refunded_amount_minor');
        });
    }
};
