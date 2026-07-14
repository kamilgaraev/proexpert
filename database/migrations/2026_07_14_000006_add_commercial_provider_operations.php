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
            $table->boolean('reconciliation_required')->default(false)->index();
            $table->timestampTz('last_reconciled_at')->nullable();
        });

        Schema::table('commercial_refunds', function (Blueprint $table): void {
            $table->string('provider_refund_id')->nullable()->change();
            $table->string('provider_idempotency_key', 64)->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->boolean('reconciliation_required')->default(true)->index();
            $table->timestampTz('last_reconciled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commercial_refunds', function (Blueprint $table): void {
            $table->dropUnique(['provider_idempotency_key']);
            $table->dropIndex(['reconciliation_required']);
            $table->dropColumn(['provider_idempotency_key', 'request_fingerprint', 'reconciliation_required', 'last_reconciled_at']);
            $table->string('provider_refund_id')->nullable(false)->change();
        });
        Schema::table('commercial_payments', function (Blueprint $table): void {
            $table->dropIndex(['reconciliation_required']);
            $table->dropColumn(['reconciliation_required', 'last_reconciled_at']);
        });
    }
};
