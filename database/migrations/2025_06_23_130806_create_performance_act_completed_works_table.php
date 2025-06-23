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
        Schema::create('performance_act_completed_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_act_id')->constrained('contract_performance_acts')->onDelete('cascade');
            $table->foreignId('completed_work_id')->constrained('completed_works')->onDelete('cascade');
            
            // Дополнительные поля для пивот таблицы
            $table->decimal('included_quantity', 15, 3)->comment('Количество работы включенное в акт (может отличаться от общего)');
            $table->decimal('included_amount', 15, 2)->comment('Сумма включенная в акт');
            $table->text('notes')->nullable()->comment('Примечания к включению работы в акт');
            
            $table->timestamps();
            
            // Индексы
            $table->index('performance_act_id');
            $table->index('completed_work_id');
            
            // Уникальность - одна работа может быть включена в акт только один раз
            $table->unique(['performance_act_id', 'completed_work_id'], 'act_work_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_act_completed_works');
    }
};
