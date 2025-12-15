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
     * Исправляем уникальное ограничение для contract_project_allocations.
     * Проблема: текущий constraint включает is_active, что не позволяет иметь
     * несколько неактивных записей для одной пары contract-project.
     * 
     * Решение: используем частичный уникальный индекс только для активных записей.
     */
    public function up(): void
    {
        // Проверяем, существует ли таблица
        if (!Schema::hasTable('contract_project_allocations')) {
            echo "⚠️  Таблица contract_project_allocations не существует. Пропускаем миграцию.\n";
            return;
        }

        // ШАГ 1: Очищаем возможные дубли активных записей
        // Оставляем только самую свежую запись для каждой пары (contract_id, project_id)
        $duplicatesQuery = "
            DELETE FROM contract_project_allocations
            WHERE id IN (
                SELECT a.id
                FROM contract_project_allocations a
                INNER JOIN contract_project_allocations b 
                    ON a.contract_id = b.contract_id 
                    AND a.project_id = b.project_id
                    AND a.is_active = true 
                    AND b.is_active = true
                    AND a.deleted_at IS NULL
                    AND b.deleted_at IS NULL
                    AND a.id < b.id
            )
        ";
        
        $deletedCount = DB::delete($duplicatesQuery);
        
        if ($deletedCount > 0) {
            echo "✅ Удалено дублей активных распределений: {$deletedCount}\n";
        }

        // ШАГ 2: Безопасно удаляем старый constraint
        try {
            Schema::table('contract_project_allocations', function (Blueprint $table) {
                $table->dropUnique('unique_active_allocation');
            });
            echo "✅ Старый constraint unique_active_allocation удален\n";
        } catch (\Exception $e) {
            // Если constraint не существует или имеет другое имя - пробуем через SQL
            try {
                DB::statement('ALTER TABLE contract_project_allocations DROP CONSTRAINT IF EXISTS unique_active_allocation');
                DB::statement('DROP INDEX IF EXISTS unique_active_allocation');
                echo "✅ Старый constraint/индекс удален через SQL\n";
            } catch (\Exception $e2) {
                echo "⚠️  Не удалось удалить старый constraint (возможно, его нет): " . $e2->getMessage() . "\n";
            }
        }
        
        // ШАГ 3: Создаем новый частичный уникальный индекс
        // Применяется только к активным не удаленным записям
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS unique_active_allocation 
            ON contract_project_allocations (contract_id, project_id) 
            WHERE is_active = true AND deleted_at IS NULL'
        );
        
        echo "✅ Создан новый частичный уникальный индекс unique_active_allocation\n";
        echo "✅ Миграция успешно завершена!\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('contract_project_allocations')) {
            return;
        }

        // Удаляем частичный индекс
        DB::statement('DROP INDEX IF EXISTS unique_active_allocation');
        
        // Восстанавливаем старый constraint (с потенциальной проблемой)
        // ВНИМАНИЕ: Это может не сработать, если есть неактивные дубли
        try {
            Schema::table('contract_project_allocations', function (Blueprint $table) {
                $table->unique(['contract_id', 'project_id', 'is_active'], 'unique_active_allocation');
            });
        } catch (\Exception $e) {
            echo "⚠️  Не удалось восстановить старый constraint: " . $e->getMessage() . "\n";
        }
    }
};

