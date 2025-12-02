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
            AND table_name = 'payment_transactions' 
            AND constraint_type = 'FOREIGN KEY'
            AND constraint_name LIKE '%invoice_id%'
            LIMIT 1
        ");

        Schema::table('payment_transactions', function (Blueprint $table) use ($foreignKey) {
            // Удаляем внешний ключ, если он существует
            if ($foreignKey && isset($foreignKey->constraint_name)) {
                try {
                    $table->dropForeign([$foreignKey->constraint_name]);
                } catch (\Exception $e) {
                    // Пробуем стандартные имена
                    $standardNames = [
                        'payment_transactions_invoice_id_foreign',
                        'payment_transactions_invoice_id_fkey'
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
            
            // Удаляем колонку invoice_id, если она существует
            if (Schema::hasColumn('payment_transactions', 'invoice_id')) {
                $table->dropColumn('invoice_id');
            }
        });
        
        // Делаем payment_document_id обязательным (отдельно, так как может быть ошибка если колонки нет)
        if (Schema::hasColumn('payment_transactions', 'payment_document_id')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->foreignId('payment_document_id')->nullable(false)->change();
            });
        }
        
        // Добавляем индекс для новой связи (если его еще нет)
        $indexExists = DB::selectOne("
            SELECT indexname 
            FROM pg_indexes 
            WHERE schemaname = 'public'
            AND tablename = 'payment_transactions' 
            AND indexname = 'payment_transactions_payment_document_id_status_index'
        ");
        
        if (!$indexExists) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->index(['payment_document_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            // Восстанавливаем invoice_id
            $table->foreignId('invoice_id')
                ->nullable()
                ->after('id')
                ->constrained('invoices')
                ->onDelete('cascade');
            
            $table->index(['invoice_id', 'status']);
            
            // Делаем payment_document_id nullable
            $table->foreignId('payment_document_id')->nullable()->change();
        });
    }
};

