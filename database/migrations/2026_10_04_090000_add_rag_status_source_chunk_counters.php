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
        Schema::table('ai_rag_status_sources', function (Blueprint $table): void {
            $table->bigInteger('chunk_count')->default(0);
            $table->bigInteger('indexed_chunk_count')->default(0);
        });
        DB::statement('LOCK TABLE ai_rag_status_sources, ai_rag_status_chunks IN SHARE ROW EXCLUSIVE MODE');
        DB::statement(<<<'SQL'
CREATE FUNCTION sync_ai_rag_status_chunk_counters() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    old_source_id bigint;
    new_source_id bigint;
    source_row record;
    chunk_delta bigint;
    indexed_delta bigint;
BEGIN
    IF TG_OP <> 'INSERT' THEN old_source_id := OLD.source_id; END IF;
    IF TG_OP <> 'DELETE' THEN new_source_id := NEW.source_id; END IF;
    IF TG_OP = 'UPDATE' AND OLD.source_id IS NOT DISTINCT FROM NEW.source_id
        AND OLD.organization_id IS NOT DISTINCT FROM NEW.organization_id
        AND OLD.project_id IS NOT DISTINCT FROM NEW.project_id
        AND OLD.embedding_present IS NOT DISTINCT FROM NEW.embedding_present THEN
        RETURN NEW;
    END IF;
    FOR source_row IN SELECT id, organization_id, project_id FROM ai_rag_status_sources
        WHERE id IN (old_source_id, new_source_id) ORDER BY id FOR UPDATE LOOP
        chunk_delta := 0;
        indexed_delta := 0;
        IF TG_OP <> 'INSERT' AND source_row.id = OLD.source_id
            AND source_row.organization_id = OLD.organization_id
            AND source_row.project_id IS NOT DISTINCT FROM OLD.project_id THEN
            chunk_delta := chunk_delta - 1;
            IF OLD.embedding_present THEN indexed_delta := indexed_delta - 1; END IF;
        END IF;
        IF TG_OP <> 'DELETE' AND source_row.id = NEW.source_id
            AND source_row.organization_id = NEW.organization_id
            AND source_row.project_id IS NOT DISTINCT FROM NEW.project_id THEN
            chunk_delta := chunk_delta + 1;
            IF NEW.embedding_present THEN indexed_delta := indexed_delta + 1; END IF;
        END IF;
        IF chunk_delta <> 0 OR indexed_delta <> 0 THEN
            UPDATE ai_rag_status_sources SET chunk_count = chunk_count + chunk_delta,
                indexed_chunk_count = indexed_chunk_count + indexed_delta WHERE id = source_row.id;
        END IF;
    END LOOP;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$
SQL);
        DB::statement(<<<'SQL'
CREATE FUNCTION refresh_ai_rag_status_source_chunk_counters() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.id IS NOT DISTINCT FROM NEW.id
        AND OLD.organization_id IS NOT DISTINCT FROM NEW.organization_id
        AND OLD.project_id IS NOT DISTINCT FROM NEW.project_id THEN
        RETURN NEW;
    END IF;
    UPDATE ai_rag_status_sources SET (chunk_count, indexed_chunk_count) = (
        SELECT COUNT(*), COUNT(*) FILTER (WHERE embedding_present) FROM ai_rag_status_chunks
        WHERE source_id = NEW.id AND organization_id = NEW.organization_id
            AND project_id IS NOT DISTINCT FROM NEW.project_id
    ) WHERE id = NEW.id;
    RETURN NEW;
END;
$$
SQL);
        DB::statement('CREATE TRIGGER ai_rag_status_chunk_counters AFTER INSERT OR UPDATE OF source_id, organization_id, project_id, embedding_present OR DELETE '
            .'ON ai_rag_status_chunks FOR EACH ROW EXECUTE FUNCTION sync_ai_rag_status_chunk_counters()');
        DB::statement('CREATE TRIGGER ai_rag_status_source_chunk_counters AFTER INSERT OR UPDATE OF id, organization_id, project_id '
            .'ON ai_rag_status_sources FOR EACH ROW EXECUTE FUNCTION refresh_ai_rag_status_source_chunk_counters()');
        DB::statement(<<<'SQL'
WITH counts AS (
    SELECT source.id, COUNT(*) AS chunk_count, COUNT(*) FILTER (WHERE chunk.embedding_present) AS indexed_chunk_count
    FROM ai_rag_status_sources source JOIN ai_rag_status_chunks chunk ON chunk.source_id = source.id
        AND chunk.organization_id = source.organization_id AND chunk.project_id IS NOT DISTINCT FROM source.project_id
    GROUP BY source.id
)
UPDATE ai_rag_status_sources source SET chunk_count = counts.chunk_count, indexed_chunk_count = counts.indexed_chunk_count
FROM counts WHERE source.id = counts.id
SQL);
        DB::statement('ALTER TABLE ai_rag_status_sources ADD CONSTRAINT ai_rag_status_chunk_counts_valid '
            .'CHECK (chunk_count >= 0 AND indexed_chunk_count >= 0 AND indexed_chunk_count <= chunk_count)');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ai_rag_status_chunk_counters ON ai_rag_status_chunks');
        DB::statement('DROP TRIGGER IF EXISTS ai_rag_status_source_chunk_counters ON ai_rag_status_sources');
        DB::statement('DROP FUNCTION IF EXISTS sync_ai_rag_status_chunk_counters()');
        DB::statement('DROP FUNCTION IF EXISTS refresh_ai_rag_status_source_chunk_counters()');
        DB::statement('ALTER TABLE ai_rag_status_sources DROP CONSTRAINT IF EXISTS ai_rag_status_chunk_counts_valid');
        Schema::table('ai_rag_status_sources', function (Blueprint $table): void {
            $table->dropColumn(['chunk_count', 'indexed_chunk_count']);
        });
    }
};
