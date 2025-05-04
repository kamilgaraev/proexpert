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
        Schema::create('work_completion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete(); // Внешний ключ на projects
            $table->foreignId('work_type_id')->constrained('work_types')->cascadeOnDelete(); // Внешний ключ на work_types
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Внешний ключ на users (прораб)
            $table->decimal('quantity', 15, 3)->nullable(); // Объем выполненной работы (опционально)
            $table->date('completion_date'); // Дата выполнения
            $table->text('notes')->nullable(); // Заметки
            $table->timestamps(); // created_at и updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_completion_logs');
    }
}; 