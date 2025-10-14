<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Индекс для contract_advance_payments
        Schema::table('contract_advance_payments', function (Blueprint $table) {
            $table->index(['contract_id', 'payment_date']);
        });

        // GIN индексы для JSON полей в supplementary_agreements
        DB::statement('CREATE INDEX IF NOT EXISTS idx_supplementary_agreements_advance_changes ON supplementary_agreements USING GIN (advance_changes)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_supplementary_agreements_subcontract_changes ON supplementary_agreements USING GIN (subcontract_changes)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_advance_payments', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'payment_date']);
        });

        DB::statement('DROP INDEX IF EXISTS idx_supplementary_agreements_advance_changes');
        DB::statement('DROP INDEX IF EXISTS idx_supplementary_agreements_subcontract_changes');
    }
};
