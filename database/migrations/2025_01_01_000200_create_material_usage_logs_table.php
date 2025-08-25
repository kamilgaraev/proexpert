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
        Schema::create('material_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete(); // Внешний ключ на projects
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete(); // Внешний ключ на materials
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Внешний ключ на users (прораб)
            $table->decimal('quantity', 15, 3); // Количество, с точностью до 3 знаков после запятой
            $table->date('usage_date'); // Дата использования
            $table->text('notes')->nullable(); // Заметки
            $table->timestamps(); // created_at и updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_usage_logs');
    }
};
