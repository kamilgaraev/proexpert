<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Удаляем уникальное ограничение на уровне БД, так как оно не учитывает soft deletes.
     * Валидация Laravel уже правильно проверяет уникальность с учетом deleted_at IS NULL.
     */
    public function up(): void
    {
        // Проверяем существование старого индекса и удаляем его
        $oldIndexName = 'supplementary_agreements_contract_id_number_unique';
        $indexExists = DB::select(
            "SELECT 1 FROM pg_indexes WHERE indexname = ? AND tablename = 'supplementary_agreements'",
            [$oldIndexName]
        );
        
        if (!empty($indexExists)) {
            // Индекс существует с ожидаемым именем
            DB::statement("DROP INDEX {$oldIndexName}");
        } else {
            // Пытаемся удалить по колонкам (если имя индекса отличается)
            try {
                Schema::table('supplementary_agreements', function (Blueprint $table) {
                    $table->dropUnique(['contract_id', 'number']);
                });
            } catch (\Exception $e) {
                // Если индекс уже не существует или имеет другое имя, игнорируем ошибку
                // и ищем индекс вручную
                $existingIndexes = DB::select(
                    "SELECT indexname FROM pg_indexes WHERE tablename = 'supplementary_agreements' AND indexdef LIKE '%contract_id%number%'"
                );
                foreach ($existingIndexes as $idx) {
                    DB::statement("DROP INDEX IF EXISTS {$idx->indexname}");
                }
            }
        }
        
        // Создаем частичный уникальный индекс в PostgreSQL, который учитывает только активные записи
        // Это позволит иметь несколько удаленных записей с одинаковым номером, но только одну активную
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS supplementary_agreements_contract_id_number_unique ON supplementary_agreements (contract_id, number) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем частичный уникальный индекс
        DB::statement('DROP INDEX IF EXISTS supplementary_agreements_contract_id_number_unique');
        
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            // Восстанавливаем обычное уникальное ограничение
            $table->unique(['contract_id', 'number']);
        });
    }
};
