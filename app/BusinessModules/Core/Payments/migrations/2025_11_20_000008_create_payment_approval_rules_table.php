<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_approval_rules', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');
            
            // Название правила
            $table->string('name');
            $table->text('description')->nullable();
            
            // Приоритет (чем выше число, тем выше приоритет)
            $table->integer('priority')->default(0);
            
            // Активность
            $table->boolean('is_active')->default(true);
            
            // Условия применения правила
            $table->json('conditions')->nullable(); // Условия для применения правила
            
            // Цепочка утверждений
            $table->json('approval_chain'); // [{role, level, order, required, amount_threshold}, ...]
            
            // Фильтры
            $table->decimal('min_amount', 15, 2)->nullable();
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->json('document_types')->nullable(); // ['payment_order', 'invoice']
            $table->json('project_ids')->nullable();
            $table->json('contractor_ids')->nullable();
            
            // Настройки
            $table->boolean('require_all_approvals')->default(true); // Все утверждения обязательны
            $table->boolean('sequential')->default(true); // Последовательное утверждение по уровням
            $table->integer('auto_approve_after_hours')->nullable(); // Автоутверждение через N часов
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index('organization_id');
            $table->index('is_active');
            $table->index('priority');
            $table->index(['organization_id', 'is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_approval_rules');
    }
};

