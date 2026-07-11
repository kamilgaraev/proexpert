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
        Schema::table('estimate_generation_pipeline_checkpoints', fn (Blueprint $table) => $table->unique(['id', 'session_id'], 'eg_checkpoints_session_scope_uq'));
        Schema::table('estimate_generation_ai_usage', fn (Blueprint $table) => $table->unique(['attempt_id', 'organization_id', 'project_id', 'session_id'], 'eg_usage_attempt_tenant_scope_uq'));

        Schema::create('estimate_generation_failure_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('fingerprint', 71)->unique('eg_failure_identities_fingerprint_uq');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('page_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('checkpoint_id')->nullable();
            $table->uuid('usage_attempt_id')->nullable();
            $table->string('stage', 40);
            $table->string('operation', 40);
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('category', 24);
            $table->string('code', 80);
            $table->timestampTz('created_at');
            $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'fingerprint'], 'eg_failure_identities_tenant_uq');
            $table->index(['organization_id', 'session_id', 'created_at'], 'eg_failure_identities_org_session_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_failure_identities_session_fk')->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreign(['document_id', 'organization_id', 'project_id', 'session_id'], 'eg_failure_identities_document_fk')->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_documents')->cascadeOnDelete();
            $table->foreign(['page_id', 'organization_id', 'project_id', 'session_id', 'document_id'], 'eg_failure_identities_page_fk')->references(['id', 'organization_id', 'project_id', 'session_id', 'document_id'])->on('estimate_generation_document_pages')->cascadeOnDelete();
            $table->foreign(['unit_id', 'organization_id', 'project_id', 'session_id', 'document_id'], 'eg_failure_identities_unit_fk')->references(['id', 'organization_id', 'project_id', 'session_id', 'document_id'])->on('estimate_generation_processing_units')->cascadeOnDelete();
            $table->foreign(['checkpoint_id', 'session_id'], 'eg_failure_identities_checkpoint_fk')->references(['id', 'session_id'])->on('estimate_generation_pipeline_checkpoints')->cascadeOnDelete();
            $table->foreign(['usage_attempt_id', 'organization_id', 'project_id', 'session_id'], 'eg_failure_identities_usage_fk')->references(['attempt_id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_ai_usage')->cascadeOnDelete();
        });

        Schema::create('estimate_generation_failure_events', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('event_id')->unique('eg_failure_events_event_uq');
            $table->uuid('correlation_id');
            $table->uuid('failure_id');
            $table->char('fingerprint', 71);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('event_type', 16);
            $table->unsignedInteger('attempt');
            $table->jsonb('safe_context')->default('{}');
            $table->string('resolution_code', 80)->nullable();
            $table->unsignedBigInteger('resolves_through_sequence')->nullable();
            $table->timestampTz('recorded_at');
            $table->foreign(['failure_id', 'organization_id', 'project_id', 'session_id', 'fingerprint'], 'eg_failure_events_identity_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'fingerprint'])->on('estimate_generation_failure_identities')->cascadeOnDelete();
            $table->unique(['sequence', 'failure_id'], 'eg_failure_events_sequence_identity_uq');
            $table->foreign(['resolves_through_sequence', 'failure_id'], 'eg_failure_events_resolution_target_fk')
                ->references(['sequence', 'failure_id'])->on('estimate_generation_failure_events');
            $table->index(['failure_id', 'sequence'], 'eg_failure_events_failure_sequence_idx');
            $table->index(['organization_id', 'session_id', 'sequence'], 'eg_failure_events_org_session_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE public.estimate_generation_failure_identities ADD CONSTRAINT eg_failure_identities_category_ck CHECK (category IN ('recoverable','user_action_required','terminal'))");
        DB::statement("ALTER TABLE public.estimate_generation_failure_identities ADD CONSTRAINT eg_failure_identities_identifier_ck CHECK (fingerprint ~ '^sha256:[0-9a-f]{64}$' AND stage ~ '^[a-z][a-z0-9_]{0,39}$' AND operation ~ '^[a-z][a-z0-9_]{0,39}$' AND code ~ '^[a-z][a-z0-9_]{0,79}$')");
        DB::statement("ALTER TABLE public.estimate_generation_failure_identities ADD CONSTRAINT eg_failure_identities_provider_model_ck CHECK ((provider IS NULL OR provider ~ '^[a-z0-9._-]{1,80}$') AND (model IS NULL OR model ~ '^[A-Za-z0-9._/-]{1,160}$'))");
        DB::statement('ALTER TABLE public.estimate_generation_failure_identities ADD CONSTRAINT eg_failure_identities_scope_ck CHECK ((page_id IS NULL OR document_id IS NOT NULL) AND (unit_id IS NULL OR document_id IS NOT NULL))');
        DB::statement("ALTER TABLE public.estimate_generation_failure_identities ADD CONSTRAINT eg_failure_identities_uuid_ck CHECK (id <> '00000000-0000-0000-0000-000000000000'::uuid)");
        DB::statement("ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_type_ck CHECK (event_type IN ('occurred','resolved'))");
        DB::statement("ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_identifier_ck CHECK (fingerprint ~ '^sha256:[0-9a-f]{64}$' AND (resolution_code IS NULL OR resolution_code ~ '^[a-z][a-z0-9_]{0,79}$'))");
        DB::statement('ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_attempt_ck CHECK (attempt BETWEEN 1 AND 1000)');
        DB::statement("ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_resolution_ck CHECK ((event_type = 'occurred' AND resolution_code IS NULL AND resolves_through_sequence IS NULL) OR (event_type = 'resolved' AND resolution_code IS NOT NULL AND resolves_through_sequence IS NOT NULL AND resolves_through_sequence > 0 AND safe_context = '{}'::jsonb))");
        DB::statement("ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_safe_context_closed_ck CHECK (jsonb_typeof(safe_context) = 'object' AND (safe_context - ARRAY['provider_code','http_class','http_code','status','safe_code','retry_after_seconds','attempt','validation_code','storage_code','claim_status','lineage_code','failure_fingerprint']) = '{}'::jsonb)");
        DB::statement('ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_safe_context_size_ck CHECK (octet_length(safe_context::text) <= 2048)');
        DB::statement("ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_safe_context_values_ck CHECK ((NOT safe_context ? 'provider_code' OR safe_context->>'provider_code' ~ '^[a-z][a-z0-9._-]{0,79}$') AND (NOT safe_context ? 'http_class' OR safe_context->>'http_class' ~ '^[1-5]xx$') AND (NOT safe_context ? 'http_code' OR (jsonb_typeof(safe_context->'http_code') = 'number' AND (safe_context->>'http_code')::integer BETWEEN 100 AND 599)) AND (NOT safe_context ? 'status' OR safe_context->>'status' ~ '^[a-z][a-z0-9_]{0,39}$') AND (NOT safe_context ? 'safe_code' OR safe_context->>'safe_code' ~ '^[a-z][a-z0-9_]{0,79}$') AND (NOT safe_context ? 'retry_after_seconds' OR (jsonb_typeof(safe_context->'retry_after_seconds') = 'number' AND (safe_context->>'retry_after_seconds')::integer BETWEEN 0 AND 86400)) AND (NOT safe_context ? 'attempt' OR (jsonb_typeof(safe_context->'attempt') = 'number' AND (safe_context->>'attempt')::integer BETWEEN 1 AND 1000)) AND (NOT safe_context ? 'failure_fingerprint' OR safe_context->>'failure_fingerprint' ~ '^sha256:[0-9a-f]{64}$') AND (NOT safe_context ? 'validation_code' OR safe_context->>'validation_code' ~ '^[a-z][a-z0-9_]{0,79}$') AND (NOT safe_context ? 'storage_code' OR safe_context->>'storage_code' ~ '^[a-z][a-z0-9_]{0,79}$') AND (NOT safe_context ? 'claim_status' OR safe_context->>'claim_status' IN ('lost','expired','stale','busy')) AND (NOT safe_context ? 'lineage_code' OR safe_context->>'lineage_code' ~ '^[a-z][a-z0-9_]{0,79}$'))");
        DB::statement("ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_safe_context_privacy_ck CHECK (lower(safe_context::text) !~ '(prompt|request|response|content|filename|file_name|path|authorization|api_key|apikey|token|secret|password|cookie|bearer|eyj[a-z0-9_-]{8,}\\.|akia[0-9a-z]{12,}|gh[pousr]_[0-9a-z]{12,}|sk-[0-9a-z]{8,})')");
        DB::statement("ALTER TABLE public.estimate_generation_failure_events ADD CONSTRAINT eg_failure_events_uuid_ck CHECK (event_id <> '00000000-0000-0000-0000-000000000000'::uuid AND correlation_id <> '00000000-0000-0000-0000-000000000000'::uuid)");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.prevent_estimate_generation_failure_history_mutation() RETURNS trigger AS $$
            BEGIN
                IF pg_trigger_depth() = 1 THEN
                    RAISE EXCEPTION 'estimate generation failure history is append-only';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql SET search_path = pg_catalog, public;

            CREATE TRIGGER eg_failure_identities_append_only_guard BEFORE UPDATE OR DELETE ON public.estimate_generation_failure_identities
            FOR EACH ROW EXECUTE FUNCTION public.prevent_estimate_generation_failure_history_mutation();
            CREATE TRIGGER eg_failure_events_append_only_guard BEFORE UPDATE OR DELETE ON public.estimate_generation_failure_events
            FOR EACH ROW EXECUTE FUNCTION public.prevent_estimate_generation_failure_history_mutation();

            CREATE FUNCTION public.validate_estimate_generation_failure_resolution() RETURNS trigger AS $$
            BEGIN
                IF NEW.event_type = 'resolved' AND NOT EXISTS (
                    SELECT 1 FROM public.estimate_generation_failure_events target
                    WHERE target.sequence = NEW.resolves_through_sequence
                      AND target.failure_id = NEW.failure_id
                      AND target.event_type = 'occurred'
                      AND target.sequence < NEW.sequence
                ) THEN
                    RAISE EXCEPTION 'resolution must reference an existing occurrence of the same failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SET search_path = pg_catalog, public;

            CREATE TRIGGER eg_failure_events_resolution_target_guard BEFORE INSERT ON public.estimate_generation_failure_events
            FOR EACH ROW EXECUTE FUNCTION public.validate_estimate_generation_failure_resolution();

            REVOKE ALL ON FUNCTION public.prevent_estimate_generation_failure_history_mutation() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.validate_estimate_generation_failure_resolution() FROM PUBLIC;

            CREATE VIEW public.estimate_generation_failures AS
            WITH occurrences AS (
                SELECT failure_id, MIN(recorded_at) AS first_seen_at, MAX(recorded_at) AS last_seen_at, COUNT(*)::bigint AS occurrence_count
                FROM public.estimate_generation_failure_events WHERE event_type = 'occurred' GROUP BY failure_id
            ), latest_occurrence AS (
                SELECT DISTINCT ON (failure_id) * FROM public.estimate_generation_failure_events
                WHERE event_type = 'occurred' ORDER BY failure_id, sequence DESC
            ), latest_resolution AS (
                SELECT DISTINCT ON (failure_id) * FROM public.estimate_generation_failure_events
                WHERE event_type = 'resolved' ORDER BY failure_id, sequence DESC
            )
            SELECT i.*, latest_occurrence.correlation_id, latest_occurrence.attempt, latest_occurrence.safe_context,
                occurrences.occurrence_count, occurrences.first_seen_at, occurrences.last_seen_at,
                CASE WHEN latest_resolution.resolves_through_sequence >= latest_occurrence.sequence THEN latest_resolution.recorded_at ELSE NULL END AS resolved_at,
                CASE WHEN latest_resolution.resolves_through_sequence >= latest_occurrence.sequence THEN latest_resolution.resolution_code ELSE NULL END AS resolution_code,
                latest_occurrence.sequence AS latest_occurrence_sequence
            FROM public.estimate_generation_failure_identities i
            JOIN occurrences ON occurrences.failure_id = i.id
            JOIN latest_occurrence ON latest_occurrence.failure_id = i.id
            LEFT JOIN latest_resolution ON latest_resolution.failure_id = i.id;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP VIEW IF EXISTS public.estimate_generation_failures');
            DB::statement('DROP FUNCTION IF EXISTS public.validate_estimate_generation_failure_resolution() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS public.prevent_estimate_generation_failure_history_mutation() CASCADE');
        }
        Schema::dropIfExists('estimate_generation_failure_events');
        Schema::dropIfExists('estimate_generation_failure_identities');
        Schema::table('estimate_generation_ai_usage', fn (Blueprint $table) => $table->dropUnique('eg_usage_attempt_tenant_scope_uq'));
        Schema::table('estimate_generation_pipeline_checkpoints', fn (Blueprint $table) => $table->dropUnique('eg_checkpoints_session_scope_uq'));
    }
};
