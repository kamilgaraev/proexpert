<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Получаем имя внешнего ключа из базы данных
        $foreignKey = DB::selectOne("
            SELECT constraint_name 
            FROM information_schema.table_constraints 
            WHERE table_schema = 'public'
            AND table_name = 'payment_schedules' 
            AND constraint_type = 'FOREIGN KEY'
            AND constraint_name LIKE '%invoice_id%'
            LIMIT 1
        ");

        // ШАГ 1: Удаляем внешний ключ (но оставляем колонку invoice_id пока)
        Schema::table('payment_schedules', function (Blueprint $table) use ($foreignKey) {
            // Удаляем внешний ключ, если он существует
            if ($foreignKey && isset($foreignKey->constraint_name)) {
                try {
                    $table->dropForeign([$foreignKey->constraint_name]);
                } catch (\Exception $e) {
                    // Пробуем стандартные имена
                    $standardNames = [
                        'payment_schedules_invoice_id_foreign',
                        'payment_schedules_invoice_id_fkey'
                    ];
                    
                    foreach ($standardNames as $name) {
                        try {
                            $table->dropForeign([$name]);
                            break;
                        } catch (\Exception $e2) {
                            // Продолжаем попытки
                        }
                    }
                }
            }
        });
        
        // ШАГ 2: Заполняем payment_document_id из invoice_id (если колонка invoice_id еще существует)
        if (Schema::hasColumn('payment_schedules', 'payment_document_id') && 
            Schema::hasColumn('payment_schedules', 'invoice_id')) {
            // Проверяем, есть ли NULL значения в payment_document_id
            $nullCount = DB::table('payment_schedules')
                ->whereNull('payment_document_id')
                ->count();
            
            if ($nullCount > 0) {
                // Пытаемся заполнить NULL значения из invoice_id через payment_documents
                // (данные должны быть мигрированы в миграции 2025_12_20_000007)
                if (Schema::hasTable('payment_documents')) {
                    // Находим payment_document_id для каждого invoice_id через таблицу payment_documents
                    // используя source_id и source_type
                    DB::statement("
                        UPDATE payment_schedules ps
                        SET payment_document_id = (
                            SELECT pd.id 
                            FROM payment_documents pd
                            WHERE pd.source_type = 'App\\\\BusinessModules\\\\Core\\\\Payments\\\\Models\\\\Invoice'
                            AND pd.source_id = ps.invoice_id
                            LIMIT 1
                        )
                        WHERE ps.payment_document_id IS NULL
                        AND ps.invoice_id IS NOT NULL
                        AND EXISTS (
                            SELECT 1 FROM payment_documents pd2
                            WHERE pd2.source_type = 'App\\\\BusinessModules\\\\Core\\\\Payments\\\\Models\\\\Invoice'
                            AND pd2.source_id = ps.invoice_id
                        )
                    ");
                    
                    // Проверяем снова
                    $remainingNullCount = DB::table('payment_schedules')
                        ->whereNull('payment_document_id')
                        ->count();
                    
                    if ($remainingNullCount > 0) {
                        // Если все еще есть NULL значения, удаляем такие записи
                        // (они не могут быть связаны с документами - возможно, это старые данные)
                        DB::table('payment_schedules')
                            ->whereNull('payment_document_id')
                            ->delete();
                    }
                } else {
                    // Если таблица payment_documents еще не создана, просто удаляем записи без payment_document_id
                    // (это означает, что данные еще не мигрированы, но мы все равно удаляем invoice_id)
                    DB::table('payment_schedules')
                        ->whereNull('payment_document_id')
                        ->delete();
                }
            }
        }
        
        // ШАГ 3: Делаем payment_document_id обязательным (если все NULL значения обработаны)
        if (Schema::hasColumn('payment_schedules', 'payment_document_id')) {
            $finalNullCount = DB::table('payment_schedules')
                ->whereNull('payment_document_id')
                ->count();
            
            if ($finalNullCount === 0) {
                Schema::table('payment_schedules', function (Blueprint $table) {
                    $table->foreignId('payment_document_id')->nullable(false)->change();
                });
            } else {
                // Если все еще есть NULL, оставляем колонку nullable
                // (это не должно произойти, но на всякий случай)
                throw new \RuntimeException(
                    "Cannot make payment_document_id NOT NULL: {$finalNullCount} schedules still have NULL values"
                );
            }
        }
        
        // ШАГ 4: Удаляем колонку invoice_id (теперь она больше не нужна)
        Schema::table('payment_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('payment_schedules', 'invoice_id')) {
                $table->dropColumn('invoice_id');
            }
        });
        
        // Добавляем индекс для новой связи (если его еще нет)
        $indexExists = DB::selectOne("
            SELECT indexname 
            FROM pg_indexes 
            WHERE schemaname = 'public'
            AND tablename = 'payment_schedules' 
            AND indexname = 'payment_schedules_payment_document_id_status_index'
        ");
        
        if (!$indexExists) {
            Schema::table('payment_schedules', function (Blueprint $table) {
                $table->index(['payment_document_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_schedules', function (Blueprint $table) {
            // Восстанавливаем invoice_id
            $table->foreignId('invoice_id')
                ->nullable()
                ->after('id')
                ->constrained('invoices')
                ->onDelete('cascade');
            
            // Делаем payment_document_id nullable
            $table->foreignId('payment_document_id')->nullable()->change();
        });
    }
};

