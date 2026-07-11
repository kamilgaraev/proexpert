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
            $table->string('failure_code', 80)->nullable()->after('last_error');
        });
        Schema::table('estimate_generation_pipeline_checkpoints', function (Blueprint $table): void {
            $table->unique(['id', 'session_id'], 'eg_checkpoints_session_scope_uq');
        });
        Schema::table('estimate_generation_ai_usage', function (Blueprint $table): void {
            $table->unique(['attempt_id', 'organization_id', 'project_id', 'session_id'], 'eg_usage_attempt_tenant_scope_uq');
        });

        Schema::create('estimate_generation_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('fingerprint', 71);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('page_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('checkpoint_id')->nullable();
            $table->uuid('usage_attempt_id')->nullable();
            $table->uuid('correlation_id');
            $table->string('stage', 40);
            $table->string('operation', 40);
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('category', 24);
            $table->string('code', 80);
            $table->unsignedInteger('attempt');
            $table->jsonb('safe_context')->default('{}');
            $table->unsignedBigInteger('occurrence_count')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_code', 80)->nullable();

            $table->unique('fingerprint', 'eg_failures_identity_uq');
            $table->index(['organization_id', 'session_id', 'resolved_at', 'last_seen_at'], 'eg_failures_org_session_active_idx');
            $table->index(['category', 'stage', 'last_seen_at'], 'eg_failures_category_stage_date_idx');
            $table->index(['code', 'last_seen_at'], 'eg_failures_code_date_idx');
            $table->index(['provider', 'model', 'last_seen_at'], 'eg_failures_provider_model_date_idx');

            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_failures_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreign(['document_id', 'organization_id', 'project_id', 'session_id'], 'eg_failures_document_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_documents')->cascadeOnDelete();
            $table->foreign(['page_id', 'organization_id', 'project_id', 'session_id', 'document_id'], 'eg_failures_page_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'document_id'])->on('estimate_generation_document_pages')->cascadeOnDelete();
            $table->foreign(['unit_id', 'organization_id', 'project_id', 'session_id', 'document_id'], 'eg_failures_unit_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'document_id'])->on('estimate_generation_processing_units')->cascadeOnDelete();
            $table->foreign(['checkpoint_id', 'session_id'], 'eg_failures_checkpoint_scope_fk')
                ->references(['id', 'session_id'])->on('estimate_generation_pipeline_checkpoints')->cascadeOnDelete();
            $table->foreign(['usage_attempt_id', 'organization_id', 'project_id', 'session_id'], 'eg_failures_usage_scope_fk')
                ->references(['attempt_id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_ai_usage')->cascadeOnDelete();
        });
        Schema::create('estimate_generation_failure_occurrences', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->uuid('failure_id');
            $table->char('fingerprint', 71);
            $table->timestampTz('seen_at');
            $table->foreign('failure_id', 'eg_failure_occurrences_failure_fk')
                ->references('id')->on('estimate_generation_failures')->cascadeOnDelete();
            $table->index(['failure_id', 'seen_at'], 'eg_failure_occurrences_failure_date_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_category_ck CHECK (category IN ('recoverable','user_action_required','terminal'))");
        DB::statement("ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_identifier_ck CHECK (fingerprint ~ '^sha256:[0-9a-f]{64}$' AND stage ~ '^[a-z][a-z0-9_]{0,39}$' AND operation ~ '^[a-z][a-z0-9_]{0,39}$' AND code ~ '^[a-z][a-z0-9_]{0,79}$' AND (resolution_code IS NULL OR resolution_code ~ '^[a-z][a-z0-9_]{0,79}$'))");
        DB::statement("ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_provider_model_ck CHECK ((provider IS NULL OR provider ~ '^[a-z0-9._-]{1,80}$') AND (model IS NULL OR model ~ '^[A-Za-z0-9._/-]{1,160}$'))");
        DB::statement('ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_occurrence_ck CHECK (occurrence_count >= 1 AND attempt >= 1)');
        DB::statement('ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_timestamp_ck CHECK (first_seen_at <= last_seen_at AND (resolved_at IS NULL OR resolved_at >= first_seen_at) AND ((resolved_at IS NULL) = (resolution_code IS NULL)))');
        DB::statement("ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_safe_context_closed_ck CHECK (jsonb_typeof(safe_context) = 'object' AND (safe_context - ARRAY['provider_code','http_class','http_code','status','safe_code','retry_after_seconds','attempt','operation','stage','reason','validation_code','storage_code','claim_status','lineage_code','failure_fingerprint','nested']) = '{}'::jsonb)");
        DB::statement("ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_safe_context_nested_closed_ck CHECK (NOT (safe_context ? 'nested') OR (jsonb_typeof(safe_context->'nested') = 'object' AND ((safe_context->'nested') - ARRAY['provider_code','http_class','http_code','status','safe_code','retry_after_seconds','attempt','operation','stage','reason','validation_code','storage_code','claim_status','lineage_code','failure_fingerprint']) = '{}'::jsonb))");
        DB::statement('ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_safe_context_size_ck CHECK (octet_length(safe_context::text) <= 4096)');
        DB::statement("ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_safe_context_privacy_ck CHECK (NOT (safe_context ?| ARRAY['prompt','request','response','content','filename','path','authorization','api_key','token','secret']) AND lower(safe_context::text) !~ '(prompt|request|response|content|filename|file_name|path|authorization|api_key|apikey|token|secret|password|cookie|bearer|eyj[a-z0-9_-]{8,}\\.|sk-[a-z0-9]{8,})')");
        DB::statement('ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_scope_ck CHECK ((page_id IS NULL OR document_id IS NOT NULL) AND (unit_id IS NULL OR document_id IS NOT NULL))');
        DB::statement("ALTER TABLE estimate_generation_failures ADD CONSTRAINT eg_failures_uuid_ck CHECK (id <> '00000000-0000-0000-0000-000000000000'::uuid AND correlation_id <> '00000000-0000-0000-0000-000000000000'::uuid)");
        DB::statement("ALTER TABLE estimate_generation_failure_occurrences ADD CONSTRAINT eg_failure_occurrences_fingerprint_ck CHECK (fingerprint ~ '^sha256:[0-9a-f]{64}$')");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION prevent_estimate_generation_failure_mutation() RETURNS trigger AS $$
            BEGIN
                IF pg_trigger_depth() = 1 AND COALESCE(current_setting('app.eg_failure_mutation', true), '') NOT IN ('record', 'resolve') THEN
                    RAISE EXCEPTION 'estimate_generation_failures has controlled mutation paths';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER eg_failures_controlled_mutation_guard
            BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_failures
            FOR EACH ROW EXECUTE FUNCTION prevent_estimate_generation_failure_mutation();

            CREATE FUNCTION prevent_estimate_generation_failure_occurrence_mutation() RETURNS trigger AS $$
            BEGIN
                IF pg_trigger_depth() = 1 AND COALESCE(current_setting('app.eg_failure_mutation', true), '') <> 'record' THEN
                    RAISE EXCEPTION 'estimate_generation_failure_occurrences is immutable';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER eg_failure_occurrences_immutable_guard
            BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_failure_occurrences
            FOR EACH ROW EXECUTE FUNCTION prevent_estimate_generation_failure_occurrence_mutation();

            CREATE FUNCTION record_estimate_generation_failure(payload jsonb) RETURNS text AS $$
            DECLARE
                result_fingerprint text;
                aggregate_created boolean := false;
                occurrence_created boolean := false;
                occurrence_fingerprint text;
            BEGIN
                PERFORM set_config('app.eg_failure_mutation', 'record', true);
                INSERT INTO estimate_generation_failures (
                    id, fingerprint, organization_id, project_id, session_id, document_id, page_id, unit_id,
                    checkpoint_id, usage_attempt_id, correlation_id, stage, operation, provider, model, category,
                    code, attempt, safe_context, occurrence_count, first_seen_at, last_seen_at
                ) VALUES (
                    (payload->>'id')::uuid, payload->>'fingerprint', (payload->>'organization_id')::bigint,
                    (payload->>'project_id')::bigint, (payload->>'session_id')::bigint,
                    (payload->>'document_id')::bigint, (payload->>'page_id')::bigint, (payload->>'unit_id')::bigint,
                    (payload->>'checkpoint_id')::bigint, (payload->>'usage_attempt_id')::uuid,
                    (payload->>'correlation_id')::uuid, payload->>'stage', payload->>'operation',
                    payload->>'provider', payload->>'model', payload->>'category', payload->>'code',
                    (payload->>'attempt')::integer, COALESCE(payload->'safe_context', '{}'::jsonb), 1,
                    (payload->>'seen_at')::timestamptz, (payload->>'seen_at')::timestamptz
                )
                ON CONFLICT (fingerprint) DO NOTHING
                RETURNING fingerprint, true INTO result_fingerprint, aggregate_created;

                IF result_fingerprint IS NULL THEN
                    SELECT fingerprint INTO result_fingerprint
                    FROM estimate_generation_failures
                    WHERE fingerprint = payload->>'fingerprint'
                      AND organization_id = (payload->>'organization_id')::bigint
                      AND project_id = (payload->>'project_id')::bigint
                      AND session_id = (payload->>'session_id')::bigint
                      AND document_id IS NOT DISTINCT FROM (payload->>'document_id')::bigint
                      AND page_id IS NOT DISTINCT FROM (payload->>'page_id')::bigint
                      AND unit_id IS NOT DISTINCT FROM (payload->>'unit_id')::bigint
                      AND stage = payload->>'stage'
                      AND operation = payload->>'operation'
                      AND provider IS NOT DISTINCT FROM payload->>'provider'
                      AND model IS NOT DISTINCT FROM payload->>'model'
                      AND category = payload->>'category'
                      AND code = payload->>'code';
                    IF result_fingerprint IS NULL THEN
                        RAISE EXCEPTION 'failure fingerprint collision';
                    END IF;
                END IF;

                INSERT INTO estimate_generation_failure_occurrences (event_id, failure_id, fingerprint, seen_at)
                VALUES ((payload->>'correlation_id')::uuid, (payload->>'id')::uuid, payload->>'fingerprint', (payload->>'seen_at')::timestamptz)
                ON CONFLICT (event_id) DO NOTHING
                RETURNING true INTO occurrence_created;

                IF NOT occurrence_created THEN
                    SELECT fingerprint INTO occurrence_fingerprint
                    FROM estimate_generation_failure_occurrences
                    WHERE event_id = (payload->>'correlation_id')::uuid;
                    IF occurrence_fingerprint IS NULL OR occurrence_fingerprint <> payload->>'fingerprint' THEN
                        RAISE EXCEPTION 'failure event collision';
                    END IF;
                ELSIF NOT aggregate_created THEN
                    UPDATE estimate_generation_failures SET
                        occurrence_count = occurrence_count + 1,
                        last_seen_at = GREATEST(last_seen_at, (payload->>'seen_at')::timestamptz),
                        attempt = GREATEST(attempt, (payload->>'attempt')::integer),
                        correlation_id = (payload->>'correlation_id')::uuid,
                        safe_context = COALESCE(payload->'safe_context', '{}'::jsonb),
                        resolved_at = NULL,
                        resolution_code = NULL
                    WHERE fingerprint = payload->>'fingerprint';
                END IF;
                PERFORM set_config('app.eg_failure_mutation', '', true);
                RETURN result_fingerprint;
            END;
            $$ LANGUAGE plpgsql;

            CREATE FUNCTION resolve_estimate_generation_failure(
                target_organization_id bigint,
                target_project_id bigint,
                target_session_id bigint,
                target_fingerprint text,
                target_resolution_code text,
                target_resolved_at timestamptz
            ) RETURNS boolean AS $$
            DECLARE
                changed integer;
            BEGIN
                PERFORM set_config('app.eg_failure_mutation', 'resolve', true);
                UPDATE estimate_generation_failures
                SET resolved_at = target_resolved_at, resolution_code = target_resolution_code
                WHERE organization_id = target_organization_id
                  AND project_id = target_project_id
                  AND session_id = target_session_id
                  AND fingerprint = target_fingerprint
                  AND resolved_at IS NULL;
                GET DIAGNOSTICS changed = ROW_COUNT;
                PERFORM set_config('app.eg_failure_mutation', '', true);
                RETURN changed = 1;
            END;
            $$ LANGUAGE plpgsql;

            CREATE FUNCTION resolve_active_estimate_generation_failures(
                target_organization_id bigint,
                target_project_id bigint,
                target_session_id bigint,
                target_document_id bigint,
                target_unit_id bigint,
                target_stage text,
                target_operation text,
                target_resolution_code text,
                target_resolved_at timestamptz
            ) RETURNS integer AS $$
            DECLARE
                changed integer;
            BEGIN
                PERFORM set_config('app.eg_failure_mutation', 'resolve', true);
                UPDATE estimate_generation_failures
                SET resolved_at = target_resolved_at, resolution_code = target_resolution_code
                WHERE organization_id = target_organization_id
                  AND project_id = target_project_id
                  AND session_id = target_session_id
                  AND document_id IS NOT DISTINCT FROM target_document_id
                  AND unit_id IS NOT DISTINCT FROM target_unit_id
                  AND stage = target_stage
                  AND operation = target_operation
                  AND resolved_at IS NULL;
                GET DIAGNOSTICS changed = ROW_COUNT;
                PERFORM set_config('app.eg_failure_mutation', '', true);
                RETURN changed;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS resolve_active_estimate_generation_failures(bigint,bigint,bigint,bigint,bigint,text,text,text,timestamptz)');
            DB::statement('DROP FUNCTION IF EXISTS resolve_estimate_generation_failure(bigint,bigint,bigint,text,text,timestamptz)');
            DB::statement('DROP FUNCTION IF EXISTS record_estimate_generation_failure(jsonb)');
            DB::statement('DROP FUNCTION IF EXISTS prevent_estimate_generation_failure_mutation() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS prevent_estimate_generation_failure_occurrence_mutation() CASCADE');
        }
        Schema::dropIfExists('estimate_generation_failure_occurrences');
        Schema::dropIfExists('estimate_generation_failures');
        Schema::table('estimate_generation_ai_usage', fn (Blueprint $table) => $table->dropUnique('eg_usage_attempt_tenant_scope_uq'));
        Schema::table('estimate_generation_pipeline_checkpoints', fn (Blueprint $table) => $table->dropUnique('eg_checkpoints_session_scope_uq'));
        Schema::table('estimate_generation_sessions', fn (Blueprint $table) => $table->dropColumn('failure_code'));
    }
};
