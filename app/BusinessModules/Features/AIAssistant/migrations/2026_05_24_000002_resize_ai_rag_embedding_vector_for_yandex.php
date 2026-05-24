<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->resizeVector(256);
    }

    public function down(): void
    {
        $this->resizeVector(1536);
    }

    private function resizeVector(int $dimensions): void
    {
        if (
            Schema::getConnection()->getDriverName() !== 'pgsql'
            || ! Schema::hasTable('ai_rag_chunks')
            || ! Schema::hasColumn('ai_rag_chunks', 'embedding')
        ) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ai_rag_chunks_embedding_hnsw_idx');
        DB::statement(
            'UPDATE ai_rag_chunks SET embedding = NULL, embedding_provider = NULL, embedding_model = NULL, embedding_created_at = NULL'
        );
        DB::statement(sprintf(
            'ALTER TABLE ai_rag_chunks ALTER COLUMN embedding TYPE vector(%d) USING NULL::vector(%d)',
            $dimensions,
            $dimensions
        ));
        DB::statement(
            'CREATE INDEX ai_rag_chunks_embedding_hnsw_idx ON ai_rag_chunks USING hnsw (embedding vector_cosine_ops)'
        );
    }
};
