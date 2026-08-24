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
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->foreignId('reverses_transaction_id')
                ->nullable()
                ->constrained('payment_transactions')
                ->restrictOnDelete();
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'payment_transactions_org_idempotency_unique'
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            $duplicateBankEventExists = DB::table('payment_transactions')
                ->select(['organization_id', 'bank_transaction_id'])
                ->whereNotNull('bank_transaction_id')
                ->where('bank_transaction_id', '<>', '')
                ->groupBy('organization_id', 'bank_transaction_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicateBankEventExists) {
                throw new RuntimeException('payment_transactions_duplicate_bank_events_require_reconciliation');
            }

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX payment_transactions_org_bank_event_unique
                    ON payment_transactions (organization_id, bank_transaction_id)
                    WHERE bank_transaction_id IS NOT NULL AND bank_transaction_id <> ''
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payment_transactions_org_bank_event_unique');
        }

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropUnique('payment_transactions_org_idempotency_unique');
            $table->dropConstrainedForeignId('reverses_transaction_id');
            $table->dropColumn('idempotency_key');
        });
    }
};
