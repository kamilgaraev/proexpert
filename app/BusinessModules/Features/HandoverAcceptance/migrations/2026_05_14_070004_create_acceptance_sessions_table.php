<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acceptance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 40)->default('planned');
            $table->jsonb('participant_user_ids')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptance_sessions');
    }
};
