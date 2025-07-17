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
            // Добавляем колонку contractor_id после user_id
            $table->foreignId('contractor_id')
                  ->nullable()
                  ->after('user_id')
                  ->constrained('contractors')
                  ->onDelete('set null');
            $table->index('contractor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('completed_works', function (Blueprint $table) {
            $table->dropForeign(['contractor_id']);
            $table->dropIndex(['contractor_id']);
            $table->dropColumn('contractor_id');
        });
    }
}; 