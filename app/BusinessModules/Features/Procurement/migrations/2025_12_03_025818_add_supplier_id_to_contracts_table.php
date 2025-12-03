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
     * Расширяет таблицу contracts для поддержки договоров поставки:
     * - Делает contractor_id nullable (для договоров поставки)
     * - Добавляет supplier_id (для договоров поставки)
     * - Добавляет contract_category для различения типов договоров
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Делаем contractor_id nullable (для договоров поставки)
            $table->foreignId('contractor_id')->nullable()->change();
            
            // Добавляем supplier_id для договоров поставки
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('contractor_id')
                ->constrained('suppliers')
                ->onDelete('restrict');
            
            // Добавляем категорию договора
            $table->string('contract_category', 50)
                ->nullable()
                ->default('work')
                ->after('supplier_id')
                ->comment('work - договор с подрядчиком, procurement - договор поставки, service - договор на услуги');
            
            // Индекс для быстрого поиска договоров поставки
            $table->index('supplier_id');
            $table->index(['organization_id', 'supplier_id']);
            $table->index('contract_category');
        });
        
        // Обновляем существующие контракты: устанавливаем категорию 'work'
        DB::table('contracts')
            ->whereNull('contract_category')
            ->update(['contract_category' => 'work']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Удаляем индексы
            $table->dropIndex(['supplier_id']);
            $table->dropIndex(['organization_id', 'supplier_id']);
            $table->dropIndex(['contract_category']);
            
            // Удаляем поля
            $table->dropColumn(['supplier_id', 'contract_category']);
            
            // Возвращаем contractor_id как NOT NULL (но это может вызвать проблемы, если есть NULL значения)
            // В реальности лучше не делать это автоматически
            // $table->foreignId('contractor_id')->nullable(false)->change();
        });
    }
};
