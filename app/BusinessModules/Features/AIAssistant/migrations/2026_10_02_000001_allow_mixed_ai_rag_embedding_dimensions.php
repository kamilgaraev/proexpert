<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasVectorColumn()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ai_rag_chunks_embedding_hnsw_idx');
        DB::statement('ALTER TABLE ai_rag_chunks ALTER COLUMN embedding TYPE vector USING embedding::vector');
        foreach ([256, 1024] as $dimensions) {
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS ai_rag_chunks_embedding_%d_hnsw_idx ON ai_rag_chunks USING hnsw ((embedding::vector(%d)) vector_cosine_ops) WHERE vector_dims(embedding) = %d',
                $dimensions,
                $dimensions,
                $dimensions,
            ));
        }
    }

    public function down(): void
    {
        if (! $this->hasVectorColumn()) {
            return;
        }
        if (DB::table('ai_rag_chunks')->whereNotNull('embedding')->whereRaw('vector_dims(embedding) <> 256')->exists()) {
            throw new RuntimeException('rag_mixed_embedding_dimensions_prevent_rollback');
        }

        foreach ([256, 1024] as $dimensions) {
            DB::statement(sprintf('DROP INDEX IF EXISTS ai_rag_chunks_embedding_%d_hnsw_idx', $dimensions));
        }
        DB::statement('ALTER TABLE ai_rag_chunks ALTER COLUMN embedding TYPE vector(256) USING embedding::vector(256)');
        DB::statement('CREATE INDEX ai_rag_chunks_embedding_hnsw_idx ON ai_rag_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    private function hasVectorColumn(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql'
            && Schema::hasTable('ai_rag_chunks')
            && Schema::hasColumn('ai_rag_chunks', 'embedding');
    }
};
