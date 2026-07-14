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
        Schema::table('commercial_payments', function (Blueprint $table): void {
            $table->boolean('reconciliation_required')->default(false);
            $table->timestampTz('last_reconciled_at')->nullable();
        });

        Schema::table('commercial_refunds', function (Blueprint $table): void {
            $table->string('provider_refund_id')->nullable()->change();
            $table->string('provider_idempotency_key', 64)->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->boolean('reconciliation_required')->default(true);
            $table->timestampTz('last_reconciled_at')->nullable();
        });
        DB::statement(<<<'SQL'
CREATE INDEX commercial_payments_reconciliation_queue_idx
ON commercial_payments ((COALESCE(last_reconciled_at, created_at)), id)
WHERE provider_payment_id IS NOT NULL
  AND (provider_status IN ('pending', 'waiting_for_capture', 'unknown') OR reconciliation_required = true)
SQL);
        DB::statement(<<<'SQL'
CREATE INDEX commercial_refunds_reconciliation_queue_idx
ON commercial_refunds ((COALESCE(last_reconciled_at, created_at)), id)
WHERE provider_refund_id IS NOT NULL
  AND (provider_status IN ('created', 'pending', 'unknown') OR reconciliation_required = true)
SQL);
    }

    public function down(): void
    {
        if (DB::table('commercial_refunds')->whereNull('provider_refund_id')->exists()) {
            throw new \RuntimeException('Cannot roll back provider operations while unbound commercial refund intents exist.');
        }
        DB::statement('DROP INDEX IF EXISTS commercial_refunds_reconciliation_queue_idx');
        DB::statement('DROP INDEX IF EXISTS commercial_payments_reconciliation_queue_idx');
        Schema::table('commercial_refunds', function (Blueprint $table): void {
            $table->dropUnique(['provider_idempotency_key']);
            $table->dropColumn(['provider_idempotency_key', 'request_fingerprint', 'reconciliation_required', 'last_reconciled_at']);
            $table->string('provider_refund_id')->nullable(false)->change();
        });
        Schema::table('commercial_payments', function (Blueprint $table): void {
            $table->dropColumn(['reconciliation_required', 'last_reconciled_at']);
        });
    }
};
