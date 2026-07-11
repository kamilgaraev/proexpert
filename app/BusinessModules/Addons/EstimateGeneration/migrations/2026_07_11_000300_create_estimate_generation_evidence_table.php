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
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
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
            DB::statement("CREATE FUNCTION eg_evidence_edge_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE parent_type text; child_type text; BEGIN SELECT type INTO parent_type FROM estimate_generation_evidence WHERE id = NEW.parent_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id; SELECT type INTO child_type FROM estimate_generation_evidence WHERE id = NEW.child_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id; IF NOT ((parent_type = 'source_fact' AND NEW.relation = 'derived_from' AND child_type IN ('extracted','measured')) OR (parent_type = 'source_fact' AND NEW.relation = 'supports' AND child_type IN ('extracted','measured','inferred')) OR (parent_type = 'source_fact' AND NEW.relation IN ('contradicts','resolves') AND child_type = 'source_fact') OR (parent_type = 'extracted' AND NEW.relation = 'derived_from' AND child_type IN ('extracted','measured','inferred')) OR (parent_type = 'extracted' AND NEW.relation = 'supports' AND child_type IN ('measured','inferred','work_item')) OR (parent_type = 'measured' AND NEW.relation IN ('derived_from','supports') AND child_type IN ('measured','inferred','work_item')) OR (parent_type = 'inferred' AND NEW.relation IN ('derived_from','supports') AND child_type IN ('inferred','work_item')) OR (parent_type = 'work_item' AND NEW.relation = 'matched_to' AND child_type = 'normative_match') OR (parent_type = 'normative_match' AND NEW.relation = 'priced_by' AND child_type = 'price')) THEN RAISE EXCEPTION 'estimate_generation.evidence_transition_invalid'; END IF; RETURN NEW; END; $$");
            DB::statement('CREATE TRIGGER eg_evidence_edge_transition_trg BEFORE INSERT ON estimate_generation_evidence_edges FOR EACH ROW EXECUTE FUNCTION eg_evidence_edge_transition_guard()');
            DB::statement("CREATE FUNCTION eg_evidence_edge_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'estimate_generation.evidence_edge_update_forbidden'; END IF; IF EXISTS (SELECT 1 FROM estimate_generation_evidence WHERE id = OLD.parent_id AND organization_id = OLD.organization_id AND project_id = OLD.project_id AND session_id = OLD.session_id) AND EXISTS (SELECT 1 FROM estimate_generation_evidence WHERE id = OLD.child_id AND organization_id = OLD.organization_id AND project_id = OLD.project_id AND session_id = OLD.session_id) THEN RAISE EXCEPTION 'estimate_generation.evidence_edge_delete_forbidden'; END IF; RETURN OLD; END; $$");
            DB::statement('CREATE TRIGGER eg_evidence_edge_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_evidence_edges FOR EACH ROW EXECUTE FUNCTION eg_evidence_edge_append_guard()');
            DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_evidence_semantic_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.producer_name NOT IN ('pdf_geometry','ocr_fact_extractor','drawing_analyzer','scope_inference','work_planner','normative_matcher','price_resolver','user_input_normalizer','pipeline','test','contract') THEN RAISE EXCEPTION 'estimate_generation.evidence_producer_invalid'; END IF;
    IF NEW.source_version !~ '^(sha256:[a-f0-9]{64}|manifest:[a-f0-9]{64}|(extractor|model|pipeline|semver):v[0-9]+(\.[0-9]+){0,3}|(catalog|price):([1-9][0-9]*|[a-f0-9-]{36})|(contract|test):[a-f0-9]{6,32}|fsnb:[0-9]{4}(\.[0-9]+)?|fgiscs:[0-9]{4}-(0[1-9]|1[0-2]))$' OR NEW.producer_version !~ '^(sha256:[a-f0-9]{64}|manifest:[a-f0-9]{64}|(extractor|model|pipeline|semver):v[0-9]+(\.[0-9]+){0,3}|(catalog|price):([1-9][0-9]*|[a-f0-9-]{36})|(contract|test):[a-f0-9]{6,32}|fsnb:[0-9]{4}(\.[0-9]+)?|fgiscs:[0-9]{4}-(0[1-9]|1[0-2]))$' THEN RAISE EXCEPTION 'estimate_generation.evidence_version_invalid'; END IF;
    IF NOT ((NEW.source_type IN ('document','document_unit') AND NEW.source_ref ~ '^document:[1-9][0-9]*$') OR (NEW.source_type = 'page_region' AND NEW.source_ref ~ '^document:[1-9][0-9]*/page:[1-9][0-9]*/region:([1-9][0-9]*|[a-f0-9]{64})$') OR (NEW.source_type = 'user_input' AND NEW.source_ref ~ '^input:([1-9][0-9]*|[a-f0-9-]{36})$') OR (NEW.source_type = 'catalog_norm' AND NEW.source_ref ~ '^norm:((gesn|fer):[0-9]+(-[0-9]+){1,5}|fsnb:[0-9]{4}-[1-9][0-9]*)$') OR (NEW.source_type = 'price_snapshot' AND NEW.source_ref ~ '^price:(fgiscs:[0-9]{4}-(0[1-9]|1[0-2])|regional:([1-9][0-9]*|[a-f0-9-]{36}))$') OR (NEW.source_type = 'pipeline' AND NEW.source_ref ~ '^pipeline:(understand_object|infer_scope|quantity_takeoff|decompose|match_normatives|price|validate|persist)$')) THEN RAISE EXCEPTION 'estimate_generation.evidence_source_ref_invalid'; END IF;
    IF NEW.type IN ('source_fact','extracted','measured') THEN
        IF NEW.locator - ARRAY['document_id','unit_type','unit_index','page','sheet','region_key','element_key','bbox','source_key'] <> '{}'::jsonb OR NOT (NEW.locator ? 'document_id' OR NEW.locator ? 'source_key') THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF (NEW.locator ? 'document_id' AND (jsonb_typeof(NEW.locator->'document_id') <> 'number' OR NEW.locator->>'document_id' !~ '^[1-9][0-9]*$')) OR (NEW.locator ? 'unit_index' AND (jsonb_typeof(NEW.locator->'unit_index') <> 'number' OR NEW.locator->>'unit_index' !~ '^[1-9][0-9]*$')) OR (NEW.locator ? 'page' AND (jsonb_typeof(NEW.locator->'page') <> 'number' OR NEW.locator->>'page' !~ '^[1-9][0-9]*$')) OR (NEW.locator ? 'sheet' AND (jsonb_typeof(NEW.locator->'sheet') <> 'number' OR NEW.locator->>'sheet' !~ '^[1-9][0-9]*$')) THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'bbox' AND (jsonb_typeof(NEW.locator->'bbox') <> 'array' OR jsonb_array_length(NEW.locator->'bbox') <> 4 OR EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.locator->'bbox') coordinate WHERE jsonb_typeof(coordinate) <> 'number' OR (coordinate #>> '{}')::numeric < 0 OR (coordinate #>> '{}')::numeric > 1000000)) THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'unit_type' AND NEW.locator->>'unit_type' NOT IN ('pdf_page','spreadsheet_sheet','raster_image','sketch','cad_drawing','text_page') THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'region_key' AND NEW.locator->>'region_key' !~ '^region:([1-9][0-9]*|[a-f0-9]{64})$' THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'element_key' AND NEW.locator->>'element_key' !~ '^element:([1-9][0-9]*|[a-f0-9]{64})$' THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'source_key' AND NEW.locator->>'source_key' !~ '^source:([1-9][0-9]*|[a-f0-9]{64})$' THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
    ELSIF NEW.type = 'inferred' THEN
        IF NEW.locator - ARRAY['inference_key','item_key'] <> '{}'::jsonb OR NEW.locator->>'inference_key' !~ '^inference:([1-9][0-9]*|[a-f0-9]{64})$' OR (NEW.locator ? 'item_key' AND NEW.locator->>'item_key' !~ '^item:([1-9][0-9]*|[a-f0-9]{64})$') THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
    ELSE
        IF NEW.locator - ARRAY['item_key'] <> '{}'::jsonb OR NEW.locator->>'item_key' !~ '^item:([1-9][0-9]*|[a-f0-9]{64})$' THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
    END IF;
    IF NEW.type IN ('source_fact','extracted') THEN
        IF NEW.value - CASE WHEN NEW.type = 'source_fact' THEN ARRAY['fact_key','fact_value','unit'] ELSE ARRAY['field_key','field_value','unit'] END <> '{}'::jsonb THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
        IF NOT NEW.value ? CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END OR NOT NEW.value ? CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END OR jsonb_typeof(NEW.value->CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) NOT IN ('number','boolean','string') OR (NEW.value ? 'unit' AND NEW.value->>'unit' NOT IN ('m','m2','m3','pcs','kg','t','h')) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
        IF COALESCE(NEW.value->>CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END, '') NOT IN ('wall_length','wall_height','area','perimeter','opening_width','opening_height','opening_count','room_area','room_type_code','floor_count','floor_height','roof_area','roof_slope','material_code','quantity','element_type_code') THEN RAISE EXCEPTION 'estimate_generation.evidence_attribute_invalid'; END IF;
        IF jsonb_typeof(NEW.value->CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) = 'string' AND NEW.value->>CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END !~ '^((material|work_type):([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9-]{36})|room_type:(bedroom|bathroom|kitchen|living|utility|corridor)|roof_type:(flat|pitched|gable|hip)|opening_type:(door|window|gate)|element_type:(wall|floor|roof|opening|room))$' THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'measured' THEN IF NEW.value - ARRAY['quantity','unit','method'] <> '{}'::jsonb OR NOT NEW.value ? 'quantity' OR NOT NEW.value ? 'unit' OR jsonb_typeof(NEW.value->'quantity') <> 'number' OR NEW.value->>'unit' NOT IN ('m','m2','m3','pcs','kg','t','h') OR (NEW.value ? 'method' AND NEW.value->>'method' NOT IN ('geometry','ocr','calculated','user_confirmed')) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'inferred' THEN IF NEW.value - ARRAY['result_code','confidence_band'] <> '{}'::jsonb OR NOT NEW.value ? 'result_code' OR NEW.value->>'result_code' !~ '^((material|work_type):([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9-]{36})|room_type:(bedroom|bathroom|kitchen|living|utility|corridor)|roof_type:(flat|pitched|gable|hip)|opening_type:(door|window|gate)|element_type:(wall|floor|roof|opening|room))$' OR (NEW.value ? 'confidence_band' AND NEW.value->>'confidence_band' NOT IN ('low','medium','high')) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'work_item' THEN IF NEW.value - ARRAY['work_code','quantity','unit'] <> '{}'::jsonb OR NOT NEW.value ? 'work_code' OR NEW.value->>'work_code' !~ '^work_type:([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9-]{36})$' OR (NEW.value ? 'quantity' AND jsonb_typeof(NEW.value->'quantity') <> 'number') OR (NEW.value ? 'unit' AND NEW.value->>'unit' NOT IN ('m','m2','m3','pcs','kg','t','h')) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'normative_match' THEN IF NEW.value - ARRAY['norm_key','score','dataset_version'] <> '{}'::jsonb OR NOT (NEW.value ?& ARRAY['norm_key','score','dataset_version']) OR jsonb_typeof(NEW.value->'score') <> 'number' OR (NEW.value->>'score')::numeric NOT BETWEEN 0 AND 1 OR NEW.value->>'norm_key' !~ '^((gesn|fer):[0-9]+(-[0-9]+){1,5}|fsnb:[0-9]{4}-[1-9][0-9]*)$' OR NEW.value->>'dataset_version' !~ '^(fsnb:[0-9]{4}(\.[0-9]+)?|catalog:([1-9][0-9]*|[a-f0-9-]{36}))$' THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'price' THEN IF NEW.value - ARRAY['amount','currency','price_version','region_code'] <> '{}'::jsonb OR NOT (NEW.value ?& ARRAY['amount','currency','price_version']) OR jsonb_typeof(NEW.value->'amount') <> 'number' OR NEW.value->>'currency' NOT IN ('RUB','USD','EUR') OR NEW.value->>'price_version' !~ '^(price:([1-9][0-9]*|[a-f0-9-]{36})|fgiscs:[0-9]{4}-(0[1-9]|1[0-2]))$' OR (NEW.value ? 'region_code' AND NEW.value->>'region_code' !~ '^[0-9]{1,6}$') THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_evidence_semantic_trg BEFORE INSERT OR UPDATE ON estimate_generation_evidence FOR EACH ROW EXECUTE FUNCTION eg_evidence_semantic_guard();
SQL);
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
            DB::statement('DROP TRIGGER IF EXISTS eg_evidence_semantic_trg ON estimate_generation_evidence');
            DB::statement('DROP TRIGGER IF EXISTS eg_evidence_edge_append_trg ON estimate_generation_evidence_edges');
            DB::statement('DROP TRIGGER IF EXISTS eg_evidence_edge_transition_trg ON estimate_generation_evidence_edges');
            DB::statement('DROP TRIGGER IF EXISTS eg_evidence_immutable_trg ON estimate_generation_evidence');
            DB::statement('DROP FUNCTION IF EXISTS eg_evidence_edge_append_guard()');
            DB::statement('DROP FUNCTION IF EXISTS eg_evidence_semantic_guard()');
            DB::statement('DROP FUNCTION IF EXISTS eg_evidence_edge_transition_guard()');
            DB::statement('DROP FUNCTION IF EXISTS eg_evidence_immutable_guard()');
        }
        Schema::dropIfExists('estimate_generation_evidence_edges');
        Schema::dropIfExists('estimate_generation_evidence');
        Schema::table('estimate_generation_sessions', function (Blueprint $table): void {
            $table->dropUnique('eg_sessions_scope_uq');
        });
    }
};
