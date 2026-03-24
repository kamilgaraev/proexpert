<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feedback_type', 100);
            $table->string('section_key')->nullable();
            $table->string('work_item_key')->nullable();
            $table->json('payload')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_feedback');
    }
};
