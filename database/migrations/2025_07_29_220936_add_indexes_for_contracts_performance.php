<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['organization_id', 'status'], 'idx_contracts_org_status');
            $table->index(['organization_id', 'end_date'], 'idx_contracts_org_end_date');
            $table->index(['total_amount'], 'idx_contracts_total_amount');
        });

        Schema::table('completed_works', function (Blueprint $table) {
            $table->index(['contract_id', 'status'], 'idx_completed_works_contract_status');
            $table->index(['status', 'total_amount'], 'idx_completed_works_status_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('idx_contracts_org_status');
            $table->dropIndex('idx_contracts_org_end_date');
            $table->dropIndex('idx_contracts_total_amount');
        });

        Schema::table('completed_works', function (Blueprint $table) {
            $table->dropIndex('idx_completed_works_contract_status');
            $table->dropIndex('idx_completed_works_status_amount');
        });
    }
};
