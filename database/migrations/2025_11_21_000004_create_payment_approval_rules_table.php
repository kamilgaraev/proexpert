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
            
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            
            // Название правила
            $table->string('name');
            $table->text('description')->nullable();
            
            // Приоритет (для определения порядка проверки)
            $table->integer('priority')->default(0);
            
            // Условия срабатывания правила
            $table->json('conditions'); // {amount_from, amount_to, document_types, projects, etc.}
            
            // Цепочка утверждений
            $table->json('approval_chain'); // [{role, level, order, required, amount_threshold}, ...]
            
            // Активность
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Индексы
            $table->index('organization_id');
            $table->index(['organization_id', 'is_active']);
            $table->index('priority');
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

