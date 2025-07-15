<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Добавляем ограничение, если ещё нет
            $table->foreign('parent_contract_id')
                  ->references('id')->on('contracts')
                  ->cascadeOnUpdate()
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['parent_contract_id']);
        });
    }
}; 