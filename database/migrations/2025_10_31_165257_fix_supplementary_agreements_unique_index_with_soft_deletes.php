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
        // В PostgreSQL, unique constraint создает и constraint, и индекс
        // Нужно удалить constraint через Laravel Schema builder или напрямую через SQL
        $constraintName = 'supplementary_agreements_contract_id_number_unique';
        
        // Сначала пытаемся удалить через Laravel Schema builder (правильный способ)
        try {
            Schema::table('supplementary_agreements', function (Blueprint $table) use ($constraintName) {
                // Пытаемся удалить по имени constraint
                try {
                    $table->dropUnique($constraintName);
                } catch (\Exception $e) {
                    // Если не получилось по имени, пытаемся по колонкам
                    $table->dropUnique(['contract_id', 'number']);
                }
            });
        } catch (\Exception $e) {
            // Если Laravel способ не сработал, удаляем напрямую через SQL
            // В PostgreSQL constraint и индекс могут иметь одно имя, но удалять нужно constraint
            DB::statement("ALTER TABLE supplementary_agreements DROP CONSTRAINT IF EXISTS {$constraintName}");
            // Также удаляем индекс на случай, если он существует отдельно
            DB::statement("DROP INDEX IF EXISTS {$constraintName}");
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
