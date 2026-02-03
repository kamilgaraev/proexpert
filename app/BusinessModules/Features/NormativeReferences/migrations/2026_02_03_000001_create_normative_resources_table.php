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
        Schema::create('normative_resources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->index(); // Код ресурса (например, 59.1.27.02-0101)
            $table->text('name'); // Наименование
            $table->string('unit')->nullable(); // Ед. измерения
            $table->decimal('price', 15, 2)->nullable(); // Цена (базисная или текущая)
            $table->string('type')->index(); // material, equipment, machine, work
            $table->string('source')->default('KSR'); // KSR, FSNB-2022, etc.
            $table->json('metadata')->nullable(); // Дополнительные данные
            $table->timestamps();

            // Индексы для быстрого поиска
            $table->index(['code', 'source']);
            $table->fullText('name'); // Для поиска по названию (если поддерживается БД)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('normative_resources');
    }
};
