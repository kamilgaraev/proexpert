<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\Contract\ContractAllocationTypeEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_project_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            
            // Тип распределения
            $table->string('allocation_type')->default(ContractAllocationTypeEnum::AUTO->value);
            
            // Выделенная сумма (для fixed)
            $table->decimal('allocated_amount', 15, 2)->nullable();
            
            // Процент от общей суммы (для percentage)
            $table->decimal('allocated_percentage', 5, 2)->nullable();
            
            // Пользовательская формула (JSON, для custom)
            $table->json('custom_formula')->nullable();
            
            // Заметки/причина распределения
            $table->text('notes')->nullable();
            
            // Флаг активности (для версионности)
            $table->boolean('is_active')->default(true);
            
            // Кто и когда создал/обновил
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы для производительности
            $table->index(['contract_id', 'is_active']);
            $table->index(['project_id', 'is_active']);
            
            // Уникальный индекс: один активный allocation на пару contract-project
            $table->unique(['contract_id', 'project_id', 'is_active'], 'unique_active_allocation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_project_allocations');
    }
};
