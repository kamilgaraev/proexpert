<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create('ai_rag_status_sources', function (Blueprint $table): void {
                $table->bigInteger('id')->primary();
                $table->bigInteger('organization_id');
                $table->bigInteger('project_id')->nullable();
                $table->string('source_type', 80);
                $table->string('entity_type', 80);
                $table->string('entity_id', 255);
                $table->jsonb('metadata');
                $table->foreign('id')->references('id')->on('ai_rag_sources')->cascadeOnDelete()->cascadeOnUpdate();
            });
            Schema::create('ai_rag_status_chunks', function (Blueprint $table): void {
                $table->bigInteger('id')->primary();
                $table->bigInteger('source_id');
                $table->bigInteger('organization_id');
                $table->bigInteger('project_id')->nullable();
                $table->boolean('embedding_present');
                $table->foreign('id')->references('id')->on('ai_rag_chunks')->cascadeOnDelete()->cascadeOnUpdate();
            });
            DB::statement(<<<'SQL'
CREATE FUNCTION sync_ai_rag_status_source() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.organization_id IS NOT DISTINCT FROM NEW.organization_id
        AND OLD.project_id IS NOT DISTINCT FROM NEW.project_id
        AND OLD.source_type IS NOT DISTINCT FROM NEW.source_type
        AND OLD.entity_type IS NOT DISTINCT FROM NEW.entity_type
        AND OLD.entity_id IS NOT DISTINCT FROM NEW.entity_id
        AND (OLD.metadata->'assistant_public_schema_revision') IS NOT DISTINCT FROM (NEW.metadata->'assistant_public_schema_revision') THEN
        RETURN NEW;
    END IF;
    INSERT INTO ai_rag_status_sources (id, organization_id, project_id, source_type, entity_type, entity_id, metadata)
    VALUES (NEW.id, NEW.organization_id, NEW.project_id, NEW.source_type, NEW.entity_type, NEW.entity_id,
        jsonb_strip_nulls(jsonb_build_object('assistant_public_schema_revision', NEW.metadata->'assistant_public_schema_revision')))
    ON CONFLICT (id) DO UPDATE SET organization_id = EXCLUDED.organization_id, project_id = EXCLUDED.project_id,
        source_type = EXCLUDED.source_type, entity_type = EXCLUDED.entity_type,
        entity_id = EXCLUDED.entity_id, metadata = EXCLUDED.metadata;
    RETURN NEW;
END;
$$
SQL);
            DB::statement(<<<'SQL'
CREATE FUNCTION sync_ai_rag_status_chunk() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.source_id IS NOT DISTINCT FROM NEW.source_id
        AND OLD.organization_id IS NOT DISTINCT FROM NEW.organization_id
        AND OLD.project_id IS NOT DISTINCT FROM NEW.project_id
        AND (OLD.embedding IS NOT NULL) = (NEW.embedding IS NOT NULL) THEN
        RETURN NEW;
    END IF;
    INSERT INTO ai_rag_status_chunks (id, source_id, organization_id, project_id, embedding_present)
    VALUES (NEW.id, NEW.source_id, NEW.organization_id, NEW.project_id, NEW.embedding IS NOT NULL)
    ON CONFLICT (id) DO UPDATE SET source_id = EXCLUDED.source_id, organization_id = EXCLUDED.organization_id,
        project_id = EXCLUDED.project_id, embedding_present = EXCLUDED.embedding_present;
    RETURN NEW;
END;
$$
SQL);
            DB::statement('CREATE TRIGGER ai_rag_status_source_sync AFTER INSERT OR UPDATE OF organization_id, project_id, source_type, entity_type, entity_id, metadata '
                .'ON ai_rag_sources FOR EACH ROW EXECUTE FUNCTION sync_ai_rag_status_source()');
            DB::statement('CREATE TRIGGER ai_rag_status_chunk_sync AFTER INSERT OR UPDATE OF source_id, organization_id, project_id, embedding '
                .'ON ai_rag_chunks FOR EACH ROW EXECUTE FUNCTION sync_ai_rag_status_chunk()');
            $this->backfill();
            DB::statement('CREATE INDEX ai_rag_status_sources_identity_idx ON ai_rag_status_sources (organization_id, source_type, entity_type, entity_id) INCLUDE (id, project_id)');
            DB::statement('CREATE INDEX ai_rag_status_chunks_scope_idx ON ai_rag_status_chunks (organization_id, source_id, project_id)');
            DB::statement('CREATE INDEX ai_rag_status_chunks_indexed_scope_idx ON ai_rag_status_chunks (organization_id, source_id, project_id) WHERE embedding_present');
        });
        DB::statement('VACUUM (ANALYZE) ai_rag_status_sources');
        DB::statement('VACUUM (ANALYZE) ai_rag_status_chunks');
    }

    private function backfill(): void
    {
        DB::statement(<<<'SQL'
INSERT INTO ai_rag_status_sources (id, organization_id, project_id, source_type, entity_type, entity_id, metadata)
SELECT id, organization_id, project_id, source_type, entity_type, entity_id,
    jsonb_strip_nulls(jsonb_build_object('assistant_public_schema_revision', metadata->'assistant_public_schema_revision'))
FROM ai_rag_sources
ON CONFLICT (id) DO UPDATE SET organization_id = EXCLUDED.organization_id, project_id = EXCLUDED.project_id,
    source_type = EXCLUDED.source_type, entity_type = EXCLUDED.entity_type,
    entity_id = EXCLUDED.entity_id, metadata = EXCLUDED.metadata
SQL);
        DB::statement(<<<'SQL'
INSERT INTO ai_rag_status_chunks (id, source_id, organization_id, project_id, embedding_present)
SELECT id, source_id, organization_id, project_id, embedding IS NOT NULL FROM ai_rag_chunks
ON CONFLICT (id) DO UPDATE SET source_id = EXCLUDED.source_id, organization_id = EXCLUDED.organization_id,
    project_id = EXCLUDED.project_id, embedding_present = EXCLUDED.embedding_present
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ai_rag_status_chunk_sync ON ai_rag_chunks');
        DB::statement('DROP TRIGGER IF EXISTS ai_rag_status_source_sync ON ai_rag_sources');
        DB::statement('DROP FUNCTION IF EXISTS sync_ai_rag_status_chunk()');
        DB::statement('DROP FUNCTION IF EXISTS sync_ai_rag_status_source()');
        Schema::dropIfExists('ai_rag_status_chunks');
        Schema::dropIfExists('ai_rag_status_sources');
    }
};
