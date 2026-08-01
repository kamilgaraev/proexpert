<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::create('estimate_generation_project_model_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('stable_key', 192);
            $table->string('entity_kind', 32);
            $table->jsonb('payload');
            $table->jsonb('evidence');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'stable_key'], 'eg_project_model_entities_scope_key_uq');
            $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_entities_scope_uq');
            $table->index(['organization_id', 'project_id', 'session_id', 'source_version', 'entity_kind'], 'eg_project_model_entities_kind_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_project_model_entities_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
        });

        Schema::create('estimate_generation_project_model_assertions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('stable_key', 192);
            $table->unsignedBigInteger('entity_id');
            $table->string('assertion_type', 64);
            $table->jsonb('payload');
            $table->jsonb('evidence');
            $table->decimal('confidence', 5, 4);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'stable_key'], 'eg_project_model_assertions_scope_key_uq');
            $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_assertions_scope_uq');
            $table->index(['entity_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_assertions_entity_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_project_model_assertions_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreign(['entity_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_assertions_entity_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])->on('estimate_generation_project_model_entities')->cascadeOnDelete();
        });

        Schema::create('estimate_generation_project_model_relations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('stable_key', 192);
            $table->unsignedBigInteger('from_entity_id');
            $table->unsignedBigInteger('to_entity_id');
            $table->string('relation_type', 64);
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'stable_key'], 'eg_project_model_relations_scope_key_uq');
            $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_relations_scope_uq');
            $table->index(['from_entity_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_relations_from_idx');
            $table->index(['to_entity_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_relations_to_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_project_model_relations_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreign(['from_entity_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_relations_from_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])->on('estimate_generation_project_model_entities')->cascadeOnDelete();
            $table->foreign(['to_entity_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_relations_to_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])->on('estimate_generation_project_model_entities')->cascadeOnDelete();
        });

        Schema::create('estimate_generation_project_model_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('stable_key', 192);
            $table->unsignedBigInteger('assertion_id');
            $table->string('correction_type', 64);
            $table->jsonb('payload');
            $table->string('reason', 1000);
            $table->unsignedBigInteger('actor_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'stable_key'], 'eg_project_model_corrections_scope_key_uq');
            $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_corrections_scope_uq');
            $table->index(['assertion_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_corrections_assertion_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_project_model_corrections_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreign(['assertion_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_corrections_assertion_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])->on('estimate_generation_project_model_assertions')->cascadeOnDelete();
            $table->foreign('actor_id', 'eg_project_model_corrections_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_project_model_entities
    ADD CONSTRAINT eg_project_model_entities_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_entities_stable_key_ck CHECK (stable_key ~ '^[a-z][a-z0-9:_-]{0,191}$'),
    ADD CONSTRAINT eg_project_model_entities_kind_ck CHECK (entity_kind IN ('room', 'wall', 'opening', 'dimension', 'table', 'structural_element', 'quantity')),
    ADD CONSTRAINT eg_project_model_entities_payload_ck CHECK (jsonb_typeof(payload) = 'object' AND payload->>'kind' = entity_kind),
    ADD CONSTRAINT eg_project_model_entities_evidence_ck CHECK (jsonb_typeof(evidence) = 'array' AND jsonb_array_length(evidence) > 0),
    ADD CONSTRAINT eg_project_model_entities_confidence_ck CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
    ADD CONSTRAINT eg_project_model_entities_size_ck CHECK (octet_length(payload::text) <= 1048576 AND octet_length(evidence::text) <= 65536);

ALTER TABLE estimate_generation_project_model_assertions
    ADD CONSTRAINT eg_project_model_assertions_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_assertions_stable_key_ck CHECK (stable_key ~ '^[a-z][a-z0-9:_-]{0,191}$'),
    ADD CONSTRAINT eg_project_model_assertions_type_ck CHECK (assertion_type ~ '^[a-z][a-z0-9_]{0,63}$'),
    ADD CONSTRAINT eg_project_model_assertions_payload_ck CHECK (jsonb_typeof(payload) = 'object'),
    ADD CONSTRAINT eg_project_model_assertions_evidence_ck CHECK (jsonb_typeof(evidence) = 'array' AND jsonb_array_length(evidence) > 0),
    ADD CONSTRAINT eg_project_model_assertions_confidence_ck CHECK (confidence BETWEEN 0 AND 1),
    ADD CONSTRAINT eg_project_model_assertions_size_ck CHECK (octet_length(payload::text) <= 1048576 AND octet_length(evidence::text) <= 65536);

ALTER TABLE estimate_generation_project_model_relations
    ADD CONSTRAINT eg_project_model_relations_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_relations_stable_key_ck CHECK (stable_key ~ '^[a-z][a-z0-9:_-]{0,191}$'),
    ADD CONSTRAINT eg_project_model_relations_type_ck CHECK (relation_type ~ '^[a-z][a-z0-9_]{0,63}$'),
    ADD CONSTRAINT eg_project_model_relations_distinct_entities_ck CHECK (from_entity_id <> to_entity_id),
    ADD CONSTRAINT eg_project_model_relations_payload_ck CHECK (jsonb_typeof(payload) = 'object'),
    ADD CONSTRAINT eg_project_model_relations_size_ck CHECK (octet_length(payload::text) <= 1048576);

ALTER TABLE estimate_generation_project_model_corrections
    ADD CONSTRAINT eg_project_model_corrections_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_corrections_stable_key_ck CHECK (stable_key ~ '^[a-z][a-z0-9:_-]{0,191}$'),
    ADD CONSTRAINT eg_project_model_corrections_type_ck CHECK (correction_type IN ('manual', 'source_reconciliation')),
    ADD CONSTRAINT eg_project_model_corrections_payload_ck CHECK (jsonb_typeof(payload) = 'object'),
    ADD CONSTRAINT eg_project_model_corrections_reason_ck CHECK (length(btrim(reason)) > 0),
    ADD CONSTRAINT eg_project_model_corrections_size_ck CHECK (octet_length(payload::text) <= 1048576);

CREATE FUNCTION eg_project_model_entity_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'estimate_generation.project_model_update_forbidden'; END IF;
    IF pg_trigger_depth() = 1 AND EXISTS (SELECT 1 FROM estimate_generation_sessions WHERE id = OLD.session_id AND organization_id = OLD.organization_id AND project_id = OLD.project_id) THEN
        RAISE EXCEPTION 'estimate_generation.project_model_delete_forbidden';
    END IF;
    RETURN OLD;
END; $$;
CREATE TRIGGER eg_project_model_entity_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_entities FOR EACH ROW EXECUTE FUNCTION eg_project_model_entity_append_guard();

CREATE FUNCTION eg_project_model_assertion_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'estimate_generation.project_model_update_forbidden'; END IF;
    IF pg_trigger_depth() = 1 AND EXISTS (SELECT 1 FROM estimate_generation_project_model_entities WHERE id = OLD.entity_id) THEN
        RAISE EXCEPTION 'estimate_generation.project_model_delete_forbidden';
    END IF;
    RETURN OLD;
END; $$;
CREATE TRIGGER eg_project_model_assertion_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_assertions FOR EACH ROW EXECUTE FUNCTION eg_project_model_assertion_append_guard();

CREATE FUNCTION eg_project_model_relation_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'estimate_generation.project_model_update_forbidden'; END IF;
    IF pg_trigger_depth() = 1 AND EXISTS (SELECT 1 FROM estimate_generation_project_model_entities WHERE id IN (OLD.from_entity_id, OLD.to_entity_id)) THEN
        RAISE EXCEPTION 'estimate_generation.project_model_delete_forbidden';
    END IF;
    RETURN OLD;
END; $$;
CREATE TRIGGER eg_project_model_relation_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_relations FOR EACH ROW EXECUTE FUNCTION eg_project_model_relation_append_guard();

CREATE FUNCTION eg_project_model_correction_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'estimate_generation.project_model_update_forbidden'; END IF;
    IF pg_trigger_depth() = 1 AND EXISTS (SELECT 1 FROM estimate_generation_project_model_assertions WHERE id = OLD.assertion_id) THEN
        RAISE EXCEPTION 'estimate_generation.project_model_delete_forbidden';
    END IF;
    RETURN OLD;
END; $$;
CREATE TRIGGER eg_project_model_correction_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_corrections FOR EACH ROW EXECUTE FUNCTION eg_project_model_correction_append_guard();
SQL);
        }
    }

    public function down(): void {}
};
