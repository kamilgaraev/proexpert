<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Проверяем по имени констрейнта, есть ли уже FK
        $constraintExists = DB::table('pg_constraint')
            ->where('conname', 'contracts_parent_contract_id_foreign')
            ->exists();

        if (!$constraintExists) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->foreign('parent_contract_id')
                      ->references('id')->on('contracts')
                      ->cascadeOnUpdate()
                      ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['parent_contract_id']);
        });
    }
}; 