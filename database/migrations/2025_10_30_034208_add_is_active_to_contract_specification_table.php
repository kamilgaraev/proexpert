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
        Schema::table('contract_specification', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('attached_at');
            
            // Индекс для поиска активной спецификации
            $table->index(['contract_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_specification', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'is_active']);
            $table->dropColumn('is_active');
        });
    }
};
