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
        Schema::create('ai_generation_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained('ai_generation_history')->onDelete('cascade');
            
            $table->string('feedback_type', 50); // accepted, edited, rejected, partially_accepted
            $table->json('accepted_items')->nullable(); // ID позиций которые приняли
            $table->json('edited_items')->nullable(); // ID + изменения
            $table->json('rejected_items')->nullable(); // ID позиций которые удалили
            
            $table->text('user_comments')->nullable();
            
            // Для будущего обучения
            $table->boolean('used_for_training')->default(false);
            $table->decimal('acceptance_rate', 5, 2)->nullable(); // процент принятых позиций
            
            $table->timestamps();
            
            $table->index('generation_id');
            $table->index(['feedback_type', 'created_at']);
            $table->index('used_for_training');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_generation_feedback');
    }
};
