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
            // Проверяем, есть ли записи с parent_contract_id перед удалением
            $hasParentContracts = \DB::table('contracts')
                ->whereNotNull('parent_contract_id')
                ->whereNull('deleted_at')
                ->exists();

            if ($hasParentContracts) {
                throw new \Exception(
                    'Обнаружены контракты с parent_contract_id! ' .
                    'Сначала выполните миграцию: php artisan contracts:migrate-to-agreements'
                );
            }

            // Удаляем foreign key, если он существует
            // Имя constraint может отличаться, проверяем все возможные варианты
            $foreignKeys = \DB::select(
                "SELECT constraint_name 
                FROM information_schema.table_constraints 
                WHERE table_name = 'contracts' 
                AND constraint_type = 'FOREIGN KEY' 
                AND constraint_name LIKE '%parent_contract%'"
            );

            foreach ($foreignKeys as $fk) {
                \DB::statement("ALTER TABLE contracts DROP CONSTRAINT {$fk->constraint_name}");
            }

            // Удаляем колонку
            $table->dropColumn('parent_contract_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_contract_id')->nullable()->after('contractor_id');
            $table->foreign('parent_contract_id')
                ->references('id')
                ->on('contracts')
                ->onDelete('cascade');
        });
    }
};

