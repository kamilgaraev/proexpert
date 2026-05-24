<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPostgres = Schema::getConnection()->getDriverName() === 'pgsql';

        if ($isPostgres) {
            $this->ensureVectorExtensionExists();
        }

        Schema::create('ai_rag_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('source_type', 80);
            $table->string('entity_type', 80);
            $table->string('entity_id', 80);
            $table->string('title');
            $table->string('checksum', 64);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('indexed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'source_type', 'entity_type', 'entity_id'],
                'ai_rag_sources_unique_entity'
            );
            $table->index(['organization_id', 'project_id', 'source_type'], 'ai_rag_sources_scope_idx');
        });

        Schema::create('ai_rag_chunks', function (Blueprint $table) use ($isPostgres): void {
            $table->id();
            $table->foreignId('source_id')->constrained('ai_rag_sources')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->string('content_hash', 64);
            $table->jsonb('metadata')->nullable();
            $table->string('embedding_provider', 40)->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->timestampTz('embedding_created_at')->nullable();
            if (! $isPostgres) {
                $table->text('embedding')->nullable();
            }
            $table->timestampsTz();

            $table->unique(['source_id', 'chunk_index'], 'ai_rag_chunks_source_index_unique');
            $table->index(['organization_id', 'project_id'], 'ai_rag_chunks_scope_idx');
        });

        if ($isPostgres) {
            DB::statement('ALTER TABLE ai_rag_chunks ADD COLUMN embedding vector(1536)');
            DB::statement(
                'CREATE INDEX ai_rag_chunks_embedding_hnsw_idx ON ai_rag_chunks USING hnsw (embedding vector_cosine_ops)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rag_chunks');
        Schema::dropIfExists('ai_rag_sources');
    }

    private function ensureVectorExtensionExists(): void
    {
        $extension = DB::selectOne(
            'SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = ?) AS installed',
            ['vector']
        );

        if ((bool) ($extension->installed ?? false)) {
            return;
        }

        throw new \RuntimeException(
            'PostgreSQL extension "vector" is required for AI assistant knowledge search. '
            .'Enable pgvector on the database server before running this migration.'
        );
    }
};
