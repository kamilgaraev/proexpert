<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 50)->default('created');
            $table->string('processing_stage', 100)->default('created');
            $table->unsignedTinyInteger('processing_progress')->default(0);
            $table->json('input_payload');
            $table->json('analysis_payload')->nullable();
            $table->json('draft_payload')->nullable();
            $table->json('problem_flags')->nullable();
            $table->foreignId('applied_estimate_id')->nullable()->constrained('estimates')->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_sessions');
    }
};
