<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_corrective_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('safety_incidents')->cascadeOnDelete();
            $table->foreignId('violation_id')->nullable()->constrained('safety_violations')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_number', 80)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_type', 40);
            $table->string('severity', 30)->default('major');
            $table->string('status', 40)->default('open');
            $table->date('due_date')->nullable();
            $table->text('resolution_comment')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('verification_comment')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['incident_id', 'status']);
            $table->index(['violation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_corrective_actions');
    }
};
