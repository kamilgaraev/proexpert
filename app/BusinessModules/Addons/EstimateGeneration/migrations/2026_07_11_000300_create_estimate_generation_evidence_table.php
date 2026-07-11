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
        Schema::table('estimate_generation_sessions', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id', 'project_id'], 'eg_sessions_scope_uq');
        });

        Schema::create('estimate_generation_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('source_type', 32);
            $table->string('source_ref', 160);
            $table->string('source_version', 80);
            $table->jsonb('locator');
            $table->jsonb('value');
            $table->decimal('confidence', 7, 6);
            $table->string('producer_name', 80);
            $table->string('producer_version', 80);
            $table->char('fingerprint', 64);
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('invalidation_reason', 80)->nullable();
            $table->unsignedInteger('invalidation_version')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'session_id', 'fingerprint'], 'eg_evidence_fingerprint_uq');
            $table->unique(['id', 'organization_id', 'project_id', 'session_id'], 'eg_evidence_scope_uq');
            $table->index(['organization_id', 'project_id', 'session_id', 'source_type', 'source_ref', 'source_version', 'invalidated_at'], 'eg_evidence_source_active_idx');
            $table->index(['session_id', 'type', 'invalidated_at'], 'eg_evidence_session_type_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_evidence_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
        });

        Schema::create('estimate_generation_evidence_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('child_id');
            $table->string('relation', 32);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'session_id', 'parent_id', 'child_id', 'relation'], 'eg_evidence_edge_uq');
            $table->index(['organization_id', 'project_id', 'session_id', 'parent_id'], 'eg_evidence_edge_parent_idx');
            $table->index(['organization_id', 'project_id', 'session_id', 'child_id'], 'eg_evidence_edge_child_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_evidence ADD CONSTRAINT eg_evidence_type_ck CHECK (type IN ('source_fact','extracted','measured','inferred','work_item','normative_match','price'))");
            DB::statement("ALTER TABLE estimate_generation_evidence ADD CONSTRAINT eg_evidence_source_type_ck CHECK (source_type IN ('document','document_unit','page_region','user_input','catalog_norm','price_snapshot','pipeline'))");
            DB::statement('ALTER TABLE estimate_generation_evidence ADD CONSTRAINT eg_evidence_confidence_ck CHECK (confidence BETWEEN 0 AND 1)');
            DB::statement("ALTER TABLE estimate_generation_evidence ADD CONSTRAINT eg_evidence_json_ck CHECK (jsonb_typeof(locator) = 'object' AND jsonb_typeof(value) = 'object')");
            DB::statement('ALTER TABLE estimate_generation_evidence ADD CONSTRAINT eg_evidence_source_version_ck CHECK (char_length(source_version) BETWEEN 1 AND 80)');
            DB::statement('ALTER TABLE estimate_generation_evidence ADD CONSTRAINT eg_evidence_invalidation_ck CHECK ((invalidated_at IS NULL AND invalidation_reason IS NULL AND invalidation_version = 0) OR (invalidated_at IS NOT NULL AND invalidation_reason IS NOT NULL AND invalidation_version > 0))');
            DB::statement("ALTER TABLE estimate_generation_evidence_edges ADD CONSTRAINT eg_evidence_edge_relation_ck CHECK (relation IN ('derived_from','supports','contradicts','resolves','matched_to','priced_by'))");
            DB::statement('ALTER TABLE estimate_generation_evidence_edges ADD CONSTRAINT eg_evidence_edge_self_ck CHECK (parent_id <> child_id)');
            DB::statement('ALTER TABLE estimate_generation_evidence_edges ADD CONSTRAINT eg_evidence_edge_parent_scope_fk FOREIGN KEY (parent_id, organization_id, project_id, session_id) REFERENCES estimate_generation_evidence (id, organization_id, project_id, session_id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE estimate_generation_evidence_edges ADD CONSTRAINT eg_evidence_edge_child_scope_fk FOREIGN KEY (child_id, organization_id, project_id, session_id) REFERENCES estimate_generation_evidence (id, organization_id, project_id, session_id) ON DELETE CASCADE');
            DB::statement("CREATE FUNCTION eg_evidence_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF ROW(OLD.organization_id, OLD.project_id, OLD.session_id, OLD.type, OLD.source_type, OLD.source_ref, OLD.source_version, OLD.locator, OLD.value, OLD.confidence, OLD.producer_name, OLD.producer_version, OLD.fingerprint, OLD.created_at) IS DISTINCT FROM ROW(NEW.organization_id, NEW.project_id, NEW.session_id, NEW.type, NEW.source_type, NEW.source_ref, NEW.source_version, NEW.locator, NEW.value, NEW.confidence, NEW.producer_name, NEW.producer_version, NEW.fingerprint, NEW.created_at) THEN RAISE EXCEPTION 'estimate_generation.evidence_is_immutable'; END IF; RETURN NEW; END; $$");
            DB::statement('CREATE TRIGGER eg_evidence_immutable_trg BEFORE UPDATE ON estimate_generation_evidence FOR EACH ROW EXECUTE FUNCTION eg_evidence_immutable_guard()');
        } else {
            Schema::table('estimate_generation_evidence_edges', function (Blueprint $table): void {
                $table->foreign('parent_id', 'eg_evidence_edge_parent_fk')->references('id')->on('estimate_generation_evidence')->cascadeOnDelete();
                $table->foreign('child_id', 'eg_evidence_edge_child_fk')->references('id')->on('estimate_generation_evidence')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS eg_evidence_immutable_trg ON estimate_generation_evidence');
            DB::statement('DROP FUNCTION IF EXISTS eg_evidence_immutable_guard()');
        }
        Schema::dropIfExists('estimate_generation_evidence_edges');
        Schema::dropIfExists('estimate_generation_evidence');
        Schema::table('estimate_generation_sessions', function (Blueprint $table): void {
            $table->dropUnique('eg_sessions_scope_uq');
        });
    }
};
