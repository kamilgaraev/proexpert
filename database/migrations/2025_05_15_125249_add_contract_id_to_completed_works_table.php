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
        Schema::table('completed_works', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('project_id')->constrained('contracts')->onDelete('set null');
            $table->index('contract_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('completed_works', function (Blueprint $table) {
            $table->dropForeign('completed_works_contract_id_foreign');
            $table->dropIndex('completed_works_contract_id_index');
            $table->dropColumn('contract_id');
        });
    }
};
