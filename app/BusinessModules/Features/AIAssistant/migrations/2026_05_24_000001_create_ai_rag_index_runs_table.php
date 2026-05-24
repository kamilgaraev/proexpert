<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rag_index_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('source_type', 80)->nullable();
            $table->string('status', 24)->index();
            $table->string('mode', 24)->index();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedInteger('indexed_chunks')->default(0);
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'created_at'], 'ai_rag_index_runs_org_status_idx');
            $table->index(['organization_id', 'project_id', 'source_type'], 'ai_rag_index_runs_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rag_index_runs');
    }
};
