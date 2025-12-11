<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Исправляем некорректные данные: удаляем распределения материалов,
     * которых нет на складе (warehouse_balances)
     * 
     * ИСТОЧНИК ИСТИНЫ: СКЛАД (warehouse_balances)
     * Нельзя распределить материал на проект, если его нет на складе!
     */
    public function up(): void
    {
        Log::info('=== НАЧАЛО МИГРАЦИИ: Очистка некорректных распределений материалов ===');

        // 1. Находим некорректные распределения (где материала нет на складе)
        $invalidAllocations = DB::table('warehouse_project_allocations as wpa')
            ->leftJoin('warehouse_balances as wb', function($join) {
                $join->on('wpa.warehouse_id', '=', 'wb.warehouse_id')
                     ->on('wpa.material_id', '=', 'wb.material_id')
                     ->on('wpa.organization_id', '=', 'wb.organization_id');
            })
            ->whereNull('wb.id') // Нет записи в warehouse_balances
            ->orWhere('wb.available_quantity', '<=', 0) // Или количество на складе = 0
            ->select([
                'wpa.id',
                'wpa.organization_id',
                'wpa.warehouse_id',
                'wpa.material_id',
                'wpa.project_id',
                'wpa.allocated_quantity',
                DB::raw('COALESCE(wb.available_quantity, 0) as warehouse_quantity')
            ])
            ->get();

        if ($invalidAllocations->isEmpty()) {
            Log::info('✓ Некорректных распределений не найдено. Данные в порядке.');
            return;
        }

        Log::warning("⚠ Найдено некорректных распределений: {$invalidAllocations->count()}");

        // 2. Логируем детали для аудита
        foreach ($invalidAllocations as $allocation) {
            Log::warning('Некорректное распределение:', [
                'allocation_id' => $allocation->id,
                'organization_id' => $allocation->organization_id,
                'warehouse_id' => $allocation->warehouse_id,
                'material_id' => $allocation->material_id,
                'project_id' => $allocation->project_id,
                'allocated_quantity' => $allocation->allocated_quantity,
                'warehouse_quantity' => $allocation->warehouse_quantity,
                'reason' => $allocation->warehouse_quantity > 0 
                    ? 'Недостаточно на складе' 
                    : 'Материал отсутствует на складе'
            ]);
        }

        // 3. Создаем backup таблицу с некорректными данными (для восстановления если нужно)
        Schema::dropIfExists('warehouse_project_allocations_backup_invalid');
        
        DB::statement('
            CREATE TABLE warehouse_project_allocations_backup_invalid AS 
            SELECT wpa.*, NOW() as backed_up_at
            FROM warehouse_project_allocations wpa
            LEFT JOIN warehouse_balances wb 
                ON wpa.warehouse_id = wb.warehouse_id 
                AND wpa.material_id = wb.material_id
                AND wpa.organization_id = wb.organization_id
            WHERE wb.id IS NULL OR wb.available_quantity <= 0
        ');

        $backedUpCount = DB::table('warehouse_project_allocations_backup_invalid')->count();
        Log::info("✓ Создан backup некорректных данных: {$backedUpCount} записей в таблице 'warehouse_project_allocations_backup_invalid'");

        // 4. Удаляем некорректные распределения
        $deletedCount = DB::table('warehouse_project_allocations as wpa')
            ->leftJoin('warehouse_balances as wb', function($join) {
                $join->on('wpa.warehouse_id', '=', 'wb.warehouse_id')
                     ->on('wpa.material_id', '=', 'wb.material_id')
                     ->on('wpa.organization_id', '=', 'wb.organization_id');
            })
            ->where(function($query) {
                $query->whereNull('wb.id')
                      ->orWhere('wb.available_quantity', '<=', 0);
            })
            ->delete();

        Log::info("✓ Удалено некорректных распределений: {$deletedCount}");

        // 5. Проверяем распределения, где количество превышает доступное на складе
        $excessAllocations = DB::select("
            SELECT 
                wpa.warehouse_id,
                wpa.material_id,
                wpa.organization_id,
                SUM(wpa.allocated_quantity) as total_allocated,
                wb.available_quantity as warehouse_quantity,
                (SUM(wpa.allocated_quantity) - wb.available_quantity) as excess
            FROM warehouse_project_allocations wpa
            JOIN warehouse_balances wb 
                ON wpa.warehouse_id = wb.warehouse_id 
                AND wpa.material_id = wb.material_id
                AND wpa.organization_id = wb.organization_id
            GROUP BY wpa.warehouse_id, wpa.material_id, wpa.organization_id, wb.available_quantity
            HAVING SUM(wpa.allocated_quantity) > wb.available_quantity
        ");

        if (!empty($excessAllocations)) {
            Log::warning("⚠ Найдено материалов с избыточным распределением: " . count($excessAllocations));
            foreach ($excessAllocations as $excess) {
                Log::warning('Избыточное распределение:', [
                    'warehouse_id' => $excess->warehouse_id,
                    'material_id' => $excess->material_id,
                    'total_allocated' => $excess->total_allocated,
                    'warehouse_quantity' => $excess->warehouse_quantity,
                    'excess' => $excess->excess,
                    'action_required' => 'Требуется корректировка распределений или приход материала на склад'
                ]);
            }
        }

        Log::info('=== МИГРАЦИЯ ЗАВЕРШЕНА ===');
        Log::info('Для восстановления удаленных данных используйте таблицу: warehouse_project_allocations_backup_invalid');
    }

    /**
     * Откат миграции - восстанавливаем данные из backup
     */
    public function down(): void
    {
        if (Schema::hasTable('warehouse_project_allocations_backup_invalid')) {
            Log::info('Восстановление удаленных распределений из backup...');
            
            DB::statement('
                INSERT INTO warehouse_project_allocations 
                (id, organization_id, warehouse_id, material_id, project_id, allocated_quantity, 
                 allocated_by_user_id, allocated_at, notes, created_at, updated_at)
                SELECT 
                    id, organization_id, warehouse_id, material_id, project_id, allocated_quantity,
                    allocated_by_user_id, allocated_at, notes, created_at, updated_at
                FROM warehouse_project_allocations_backup_invalid
                ON CONFLICT (id) DO NOTHING
            ');
            
            $restoredCount = DB::table('warehouse_project_allocations_backup_invalid')->count();
            Log::info("Восстановлено записей: {$restoredCount}");
            
            // Не удаляем backup при откате - оставляем для анализа
            Log::info('Backup таблица сохранена: warehouse_project_allocations_backup_invalid');
        } else {
            Log::warning('Backup таблица не найдена. Нечего восстанавливать.');
        }
    }
};

