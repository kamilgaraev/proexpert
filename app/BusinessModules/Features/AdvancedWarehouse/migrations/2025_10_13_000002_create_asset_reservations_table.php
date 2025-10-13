<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицу резервирований активов
     */
    public function up(): void
    {
        Schema::create('asset_reservations', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            
            // Количество
            $table->decimal('quantity', 15, 3);
            
            // Для кого зарезервировано
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('reserved_by')->constrained('users')->onDelete('cascade');
            
            // Статус
            $table->enum('status', ['active', 'fulfilled', 'cancelled', 'expired'])->default('active');
            
            // Даты
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            
            // Примечания
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['organization_id', 'status'], 'idx_reserv_org_status');
            $table->index(['warehouse_id', 'material_id', 'status'], 'idx_reserv_wh_mat_status');
            $table->index(['expires_at', 'status'], 'idx_reserv_expires');
            $table->index('project_id', 'idx_reserv_project');
        });
    }

    /**
     * Откатываем миграцию
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_reservations');
    }
};

