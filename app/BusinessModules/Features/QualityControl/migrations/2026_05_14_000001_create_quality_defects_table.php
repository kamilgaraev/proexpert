<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_defects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('defect_number', 80);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 32)->default('major');
            $table->string('status', 40)->default('draft');
            $table->string('location_name')->nullable();
            $table->unsignedBigInteger('schedule_task_id')->nullable();
            $table->unsignedBigInteger('construction_journal_entry_id')->nullable();
            $table->unsignedBigInteger('completed_work_id')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('inspection_required')->default(true);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'defect_number']);
            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['organization_id', 'assigned_to', 'status']);
            $table->index(['organization_id', 'due_date']);
            $table->index(['organization_id', 'schedule_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_defects');
    }
};
