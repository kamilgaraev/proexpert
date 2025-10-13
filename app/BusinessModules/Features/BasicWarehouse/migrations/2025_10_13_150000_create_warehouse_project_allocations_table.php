<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицу распределения материалов со склада по проектам
     */
    public function up(): void
    {
        Schema::create('warehouse_project_allocations', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            
            // Распределенное количество
            $table->decimal('allocated_quantity', 15, 3)->default(0);
            
            // Метаданные
            $table->foreignId('allocated_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('allocated_at')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['warehouse_id', 'project_id'], 'idx_warehouse_project');
            $table->index(['project_id', 'material_id'], 'idx_project_material');
            $table->index(['organization_id', 'project_id'], 'idx_org_project');
            
            // Уникальность: один материал на складе может быть распределен на проект только один раз
            $table->unique(['warehouse_id', 'material_id', 'project_id'], 'unq_warehouse_material_project');
        });
    }

    /**
     * Откатываем миграцию
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_project_allocations');
    }
};

