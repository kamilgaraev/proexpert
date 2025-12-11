<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Удаляем таблицу material_usage_logs - старая система учета материалов
     * 
     * ИСТОЧНИК ИСТИНЫ теперь: warehouse_balances + warehouse_movements
     * Старая система material_usage_logs - пережиток прошлого
     */
    public function up(): void
    {
        Log::info('=== УДАЛЕНИЕ ТАБЛИЦЫ material_usage_logs (старая система) ===');

        if (!Schema::hasTable('material_usage_logs')) {
            Log::info('✓ Таблица material_usage_logs уже не существует.');
            return;
        }

        // Проверяем, сколько записей
        $recordsCount = DB::table('material_usage_logs')->count();
        Log::info("Записей в таблице: {$recordsCount}");

        // Создаем backup для аудита (если нужно будет посмотреть старые данные)
        if ($recordsCount > 0) {
            Log::info('Создаем backup таблицы...');
            
            Schema::dropIfExists('material_usage_logs_archive');
            
            DB::statement('
                CREATE TABLE material_usage_logs_archive AS 
                SELECT *, NOW() as archived_at
                FROM material_usage_logs
            ');
            
            Log::info("✓ Backup создан: material_usage_logs_archive ({$recordsCount} записей)");
        }

        // Удаляем таблицу
        Schema::dropIfExists('material_usage_logs');
        
        Log::info('✓ Таблица material_usage_logs удалена');
        Log::info('=== МИГРАЦИЯ ЗАВЕРШЕНА ===');
        Log::info('Теперь используется только складская система: warehouse_balances + warehouse_movements');
    }

    /**
     * Откат - восстанавливаем таблицу из backup
     */
    public function down(): void
    {
        if (Schema::hasTable('material_usage_logs_archive')) {
            Log::info('Восстановление таблицы material_usage_logs из архива...');
            
            // Воссоздаем структуру таблицы (базовая схема)
            Schema::create('material_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->onDelete('cascade');
                $table->foreignId('project_id')->constrained()->onDelete('cascade');
                $table->foreignId('material_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
                $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
                $table->foreignId('work_type_id')->nullable()->constrained()->onDelete('set null');
                $table->enum('operation_type', ['receipt', 'write_off']);
                $table->decimal('quantity', 15, 3);
                $table->decimal('unit_price', 15, 2)->nullable();
                $table->decimal('total_price', 15, 2)->nullable();
                $table->date('usage_date');
                $table->string('document_number')->nullable();
                $table->text('notes')->nullable();
                $table->string('photo_path')->nullable();
                $table->timestamps();
                
                $table->index(['project_id', 'usage_date']);
                $table->index(['material_id', 'usage_date']);
                $table->index(['organization_id', 'usage_date']);
            });
            
            // Восстанавливаем данные
            DB::statement('
                INSERT INTO material_usage_logs 
                SELECT id, organization_id, project_id, material_id, user_id, supplier_id, 
                       work_type_id, operation_type, quantity, unit_price, total_price, 
                       usage_date, document_number, notes, photo_path, created_at, updated_at
                FROM material_usage_logs_archive
            ');
            
            $restoredCount = DB::table('material_usage_logs')->count();
            Log::info("✓ Восстановлено записей: {$restoredCount}");
        } else {
            Log::warning('Архив не найден. Создается пустая таблица material_usage_logs.');
            
            Schema::create('material_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->onDelete('cascade');
                $table->foreignId('project_id')->constrained()->onDelete('cascade');
                $table->foreignId('material_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
                $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
                $table->foreignId('work_type_id')->nullable()->constrained()->onDelete('set null');
                $table->enum('operation_type', ['receipt', 'write_off']);
                $table->decimal('quantity', 15, 3);
                $table->decimal('unit_price', 15, 2)->nullable();
                $table->decimal('total_price', 15, 2)->nullable();
                $table->date('usage_date');
                $table->string('document_number')->nullable();
                $table->text('notes')->nullable();
                $table->string('photo_path')->nullable();
                $table->timestamps();
                
                $table->index(['project_id', 'usage_date']);
                $table->index(['material_id', 'usage_date']);
                $table->index(['organization_id', 'usage_date']);
            });
        }
    }
};

