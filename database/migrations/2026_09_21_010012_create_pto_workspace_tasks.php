<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pto_workspace_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('source_key', 160);
            $table->string('title', 255);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status', 24)->default('open');
            $table->string('origin', 24);
            $table->foreignId('document_set_id')->nullable()->constrained('executive_document_sets')->nullOnDelete();
            $table->unsignedBigInteger('requirement_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'source_key'], 'pto_workspace_tasks_source_unique');
            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['responsible_user_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pto_workspace_tasks');
    }
};
