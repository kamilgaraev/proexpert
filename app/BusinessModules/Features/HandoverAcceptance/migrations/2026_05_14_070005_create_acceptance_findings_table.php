<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acceptance_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->cascadeOnDelete();
            $table->foreignId('acceptance_session_id')->nullable()->constrained('acceptance_sessions')->nullOnDelete();
            $table->foreignId('quality_defect_id')->nullable()->constrained('quality_defects')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 40)->default('major');
            $table->string('status', 40)->default('open');
            $table->text('resolution_comment')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptance_findings');
    }
};
