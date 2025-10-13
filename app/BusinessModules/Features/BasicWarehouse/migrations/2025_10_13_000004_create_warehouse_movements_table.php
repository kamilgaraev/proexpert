<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицу движений активов на складе
     */
    public function up(): void
    {
        Schema::create('warehouse_movements', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            
            // Тип движения
            $table->enum('movement_type', ['receipt', 'write_off', 'transfer_in', 'transfer_out', 'adjustment', 'return'])->default('receipt');
            
            // Количество и цена
            $table->decimal('quantity', 15, 3);
            $table->decimal('price', 15, 2)->nullable();
            
            // Связанные склады (для transfer)
            $table->foreignId('from_warehouse_id')->nullable()->constrained('organization_warehouses')->onDelete('set null');
            $table->foreignId('to_warehouse_id')->nullable()->constrained('organization_warehouses')->onDelete('set null');
            
            // Связанные документы
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Дополнительная информация
            $table->string('document_number', 100)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamp('movement_date')->useCurrent();
            $table->timestamps();
            
            // Индексы
            $table->index(['organization_id', 'warehouse_id', 'movement_date'], 'idx_movements_org_wh_date');
            $table->index(['material_id', 'movement_type'], 'idx_movements_material_type');
            $table->index(['movement_date', 'movement_type'], 'idx_movements_date_type');
            $table->index('document_number', 'idx_movements_document');
        });
    }

    /**
     * Откатываем миграцию
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_movements');
    }
};

