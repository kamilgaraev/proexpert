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
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'most_report_publication_owner') THEN
                    CREATE ROLE most_report_publication_owner NOLOGIN NOINHERIT;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'most_report_publication_issuer') THEN
                    CREATE ROLE most_report_publication_issuer NOLOGIN NOINHERIT;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'most_report_publication_operator') THEN
                    CREATE ROLE most_report_publication_operator NOLOGIN NOINHERIT;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'most_report_publication_runtime') THEN
                    CREATE ROLE most_report_publication_runtime NOLOGIN NOINHERIT;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'most_report_publication_outbox_worker') THEN
                    CREATE ROLE most_report_publication_outbox_worker NOLOGIN NOINHERIT;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM pg_roles
                    WHERE rolname IN (
                        'most_report_publication_owner',
                        'most_report_publication_issuer',
                        'most_report_publication_operator',
                        'most_report_publication_runtime',
                        'most_report_publication_outbox_worker'
                    )
                    AND (rolcanlogin OR rolinherit OR rolsuper OR rolcreaterole OR rolcreatedb OR rolreplication OR rolbypassrls)
                ) THEN
                    RAISE EXCEPTION 'report_publication_role_shape_invalid' USING ERRCODE = '42501';
                END IF;
                EXECUTE format('GRANT most_report_publication_owner TO %I', current_user);
                GRANT USAGE, CREATE ON SCHEMA public TO most_report_publication_owner;
            END;
            $$;
            SQL);

        Schema::create('report_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 64);
            $table->string('status', 16);
            $table->jsonb('candidate_definition_json');
            $table->jsonb('proof_json');
            $table->char('proof_sha256', 64);
            $table->char('candidate_manifest_sha256', 64);
            $table->char('candidate_definition_sha256', 64);
            $table->char('official_manifest_sha256', 64);
            $table->char('binding_sha256', 64);
            $table->char('conformance_evidence_sha256', 64);
            $table->string('contract_version', 32);
            $table->string('source_schema_version', 32);
            $table->string('formula_version', 32);
            $table->string('renderer_version', 32);
            $table->char('release_git_sha', 40);
            $table->text('release_artifact_json');
            $table->char('release_artifact_sha256', 64);
            $table->string('release_issuer', 64);
            $table->string('release_key_id', 64);
            $table->string('published_by', 128);
            $table->timestampTz('published_at', 6);
            $table->timestampTz('disabled_at', 6)->nullable();
            $table->string('disabled_reason', 128)->nullable();
            $table->string('disabled_by', 128)->nullable();

            $table->unique(['id', 'proof_sha256'], 'report_publications_identity_proof_unique');
            $table->index(['code', 'published_at'], 'report_publications_code_history_index');
        });

        Schema::create('report_publication_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('publication_id');
            $table->string('event_type', 16);
            $table->string('actor_identity', 128);
            $table->char('release_git_sha', 40);
            $table->char('payload_sha256', 64);
            $table->timestampTz('occurred_at', 6);

            $table->foreign('publication_id', 'report_publication_events_publication_foreign')
                ->references('id')
                ->on('report_publications')
                ->restrictOnDelete();
            $table->unique(
                ['publication_id', 'event_type'],
                'report_publication_events_transition_unique',
            );
            $table->index(['publication_id', 'occurred_at'], 'report_publication_events_history_index');
        });

        Schema::create('report_publication_features', function (Blueprint $table): void {
            $table->string('code', 64)->primary();
            $table->ulid('publication_id');
            $table->char('proof_sha256', 64);
            $table->string('mode', 16);
            $table->jsonb('canary_organization_ids');
            $table->jsonb('canary_user_ids');
            $table->timestampTz('updated_at', 6);

            $table->foreign(
                ['publication_id', 'proof_sha256'],
                'report_publication_features_identity_foreign',
            )
                ->references(['id', 'proof_sha256'])
                ->on('report_publications')
                ->restrictOnDelete();
        });

        Schema::create('report_publication_outbox', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('publication_id');
            $table->string('event_type', 32);
            $table->string('deduplication_key', 191)->unique();
            $table->jsonb('payload_json');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('delivered_at', 6)->nullable();

            $table->foreign('publication_id', 'report_publication_outbox_publication_foreign')
                ->references('id')
                ->on('report_publications')
                ->restrictOnDelete();
            $table->index(['delivered_at', 'created_at'], 'report_publication_outbox_delivery_index');
        });

        DB::statement("ALTER TABLE report_publications ADD CONSTRAINT report_publications_code_check CHECK ((code COLLATE \"C\") ~ '\\A[a-z][a-z0-9_]{2,63}\\Z')");
        DB::statement("ALTER TABLE report_publications ADD CONSTRAINT report_publications_status_check CHECK (status IN ('published', 'disabled'))");
        foreach ([
            'proof_sha256',
            'candidate_manifest_sha256',
            'candidate_definition_sha256',
            'official_manifest_sha256',
            'binding_sha256',
            'conformance_evidence_sha256',
            'release_artifact_sha256',
        ] as $column) {
            DB::statement("ALTER TABLE report_publications ADD CONSTRAINT report_publications_{$column}_check CHECK (({$column} COLLATE \"C\") ~ '\\A[a-f0-9]{64}\\Z')");
        }
        DB::statement("ALTER TABLE report_publications ADD CONSTRAINT report_publications_release_sha_check CHECK ((release_git_sha COLLATE \"C\") ~ '\\A[a-f0-9]{40}\\Z')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_release_checks_match(
                artifact_json jsonb,
                proof_document jsonb
            )
            RETURNS boolean
            LANGUAGE sql
            IMMUTABLE
            STRICT
            PARALLEL SAFE
            AS $$
                SELECT jsonb_typeof(artifact_json -> 'evidence' -> 'checks') = 'object'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM jsonb_each_text(artifact_json -> 'evidence' -> 'checks') AS check_state
                        WHERE check_state.value <> 'passed'
                    )
                    AND (
                        SELECT jsonb_agg(check_name ORDER BY check_name)
                        FROM jsonb_object_keys(artifact_json -> 'evidence' -> 'checks') AS check_name
                    ) = (proof_document -> 'ci' -> 'required_checks')
            $$;
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE report_publications
                ADD CONSTRAINT report_publications_state_shape_check
                CHECK (
                    (btrim(published_by) <> '' AND char_length(published_by) <= 128)
                    AND (
                        (status = 'published' AND disabled_at IS NULL AND disabled_reason IS NULL AND disabled_by IS NULL)
                        OR (
                            status = 'disabled'
                            AND disabled_at IS NOT NULL
                            AND disabled_reason IS NOT NULL
                            AND disabled_by IS NOT NULL
                            AND btrim(disabled_by) <> ''
                            AND char_length(disabled_by) <= 128
                        )
                    )
                )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE report_publications
                ADD CONSTRAINT report_publications_candidate_shape_check
                CHECK (
                    (jsonb_typeof(candidate_definition_json) = 'object') IS TRUE
                    AND (jsonb_typeof(candidate_definition_json -> 'code') = 'string') IS TRUE
                    AND ((candidate_definition_json ->> 'code') = code) IS TRUE
                    AND (jsonb_typeof(candidate_definition_json -> 'readiness') = 'object') IS TRUE
                    AND ((candidate_definition_json -> 'readiness' ->> 'publication') = 'candidate') IS TRUE
                )
            SQL);
        DB::unprepared(<<<'SQL'
            ALTER TABLE report_publications
                ADD CONSTRAINT report_publications_proof_shape_check
                CHECK (
                    (jsonb_typeof(proof_json) = 'object') IS TRUE
                    AND (proof_json ?& ARRAY[
                        'code', 'candidate_manifest_sha256', 'candidate_definition_sha256',
                        'binding_sha256', 'contract_version', 'versions', 'semantic_fingerprints',
                        'fixture_sha256', 'conformance_evidence_sha256', 'source', 'formula',
                        'components', 'permissions', 'export_contracts', 'drill_down_contract',
                        'ci', 'release'
                    ]) IS TRUE
                    AND ((proof_json - ARRAY[
                        'code', 'candidate_manifest_sha256', 'candidate_definition_sha256',
                        'binding_sha256', 'contract_version', 'versions', 'semantic_fingerprints',
                        'fixture_sha256', 'conformance_evidence_sha256', 'source', 'formula',
                        'components', 'permissions', 'export_contracts', 'drill_down_contract',
                        'ci', 'release'
                    ]) = '{}'::jsonb) IS TRUE
                    AND ((proof_json ->> 'code') = code) IS TRUE
                    AND ((proof_json ->> 'candidate_manifest_sha256') = candidate_manifest_sha256) IS TRUE
                    AND ((proof_json ->> 'candidate_definition_sha256') = candidate_definition_sha256) IS TRUE
                    AND ((proof_json ->> 'binding_sha256') = binding_sha256) IS TRUE
                    AND ((proof_json ->> 'conformance_evidence_sha256') = conformance_evidence_sha256) IS TRUE
                    AND ((proof_json ->> 'contract_version') = contract_version) IS TRUE
                    AND ((proof_json -> 'versions' ->> 'source_schema') = source_schema_version) IS TRUE
                    AND ((proof_json -> 'versions' ->> 'formula') = formula_version) IS TRUE
                    AND ((proof_json -> 'versions' ->> 'contract') = contract_version) IS TRUE
                    AND ((proof_json -> 'versions' ->> 'renderer') = renderer_version) IS TRUE
                    AND ((proof_json -> 'release' ->> 'git_sha') = release_git_sha) IS TRUE
                    AND ((proof_json -> 'release' ->> 'approver_identity') = published_by) IS TRUE
                    AND ((proof_json -> 'release' ->> 'created_at_utc') =
                        to_char(published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')) IS TRUE
                    AND ((proof_json -> 'ci' ->> 'commit_sha') = release_git_sha) IS TRUE
                )
            SQL);
        DB::unprepared(<<<'SQL'
            ALTER TABLE report_publications
                ADD CONSTRAINT report_publications_release_artifact_shape_check
                CHECK ((
                    (jsonb_typeof(release_artifact_json::jsonb) = 'object') IS TRUE
                    AND (release_artifact_json::jsonb ?& ARRAY[
                        'algorithm', 'artifact_id', 'evidence', 'issuer', 'key_id',
                        'provenance', 'schema_version', 'signature', 'subject'
                    ]) IS TRUE
                    AND ((release_artifact_json::jsonb - ARRAY[
                        'algorithm', 'artifact_id', 'evidence', 'issuer', 'key_id',
                        'provenance', 'schema_version', 'signature', 'subject'
                    ]) = '{}'::jsonb) IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'algorithm') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'artifact_id') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'issuer') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'key_id') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'schema_version') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'signature') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance') = 'object') IS TRUE
                    AND ((release_artifact_json::jsonb -> 'provenance') ?& ARRAY[
                        'artifact_name', 'commit_sha', 'environment', 'event_name', 'job',
                        'ref', 'repository', 'run_attempt', 'run_id', 'workflow_ref'
                    ]) IS TRUE
                    AND (((release_artifact_json::jsonb -> 'provenance') - ARRAY[
                        'artifact_name', 'commit_sha', 'environment', 'event_name', 'job',
                        'ref', 'repository', 'run_attempt', 'run_id', 'workflow_ref'
                    ]) = '{}'::jsonb) IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'artifact_name') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'commit_sha') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'environment') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'event_name') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'job') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'ref') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'repository') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'run_attempt') = 'number') IS TRUE
                    AND ((release_artifact_json::jsonb -> 'provenance' ->> 'run_attempt') COLLATE "C") ~ '\A[1-9][0-9]*\Z'
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'run_id') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'workflow_ref') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'evidence') = 'object') IS TRUE
                    AND ((release_artifact_json::jsonb -> 'evidence') ?& ARRAY[
                        'checks', 'commit_sha', 'completed_at_utc', 'run_id'
                    ]) IS TRUE
                    AND (((release_artifact_json::jsonb -> 'evidence') - ARRAY[
                        'checks', 'commit_sha', 'completed_at_utc', 'run_id'
                    ]) = '{}'::jsonb) IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'evidence' -> 'checks') = 'object') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'evidence' -> 'commit_sha') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'evidence' -> 'completed_at_utc') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'evidence' -> 'run_id') = 'string') IS TRUE
                    AND (release_artifact_json::jsonb ->> 'artifact_id') = 'most.report_publication.release'
                    AND (release_artifact_json::jsonb ->> 'schema_version') = '1.0.0'
                    AND (release_artifact_json::jsonb ->> 'algorithm') = 'ed25519'
                    AND (release_artifact_json::jsonb ->> 'issuer') = release_issuer
                    AND (release_artifact_json::jsonb ->> 'key_id') = release_key_id
                    AND ((release_artifact_json::jsonb ->> 'signature') COLLATE "C") ~ '\A[A-Za-z0-9_-]{86}\Z'
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject') = 'object') IS TRUE
                    AND ((release_artifact_json::jsonb -> 'subject') ?& ARRAY[
                        'approver_identity', 'binding_sha256', 'candidate_definition_sha256',
                        'candidate_manifest_sha256', 'code', 'conformance_evidence_sha256',
                        'official_manifest_sha256', 'proof_sha256', 'release_created_at_utc',
                        'release_git_sha'
                    ]) IS TRUE
                    AND (((release_artifact_json::jsonb -> 'subject') - ARRAY[
                        'approver_identity', 'binding_sha256', 'candidate_definition_sha256',
                        'candidate_manifest_sha256', 'code', 'conformance_evidence_sha256',
                        'official_manifest_sha256', 'proof_sha256', 'release_created_at_utc',
                        'release_git_sha'
                    ]) = '{}'::jsonb) IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'approver_identity') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'binding_sha256') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'candidate_definition_sha256') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'candidate_manifest_sha256') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'code') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'conformance_evidence_sha256') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'official_manifest_sha256') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'proof_sha256') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'release_created_at_utc') = 'string') IS TRUE
                    AND (jsonb_typeof(release_artifact_json::jsonb -> 'subject' -> 'release_git_sha') = 'string') IS TRUE
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'code') = code
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'candidate_manifest_sha256') = candidate_manifest_sha256
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'candidate_definition_sha256') = candidate_definition_sha256
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'official_manifest_sha256') = official_manifest_sha256
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'binding_sha256') = binding_sha256
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'conformance_evidence_sha256') = conformance_evidence_sha256
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'proof_sha256') = proof_sha256
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'release_git_sha') = release_git_sha
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'approver_identity') = published_by
                    AND (release_artifact_json::jsonb -> 'subject' ->> 'release_created_at_utc') =
                        to_char(published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')
                    AND (release_artifact_json::jsonb -> 'provenance' ->> 'commit_sha') = release_git_sha
                    AND (release_artifact_json::jsonb -> 'evidence' ->> 'commit_sha') = release_git_sha
                    AND (release_artifact_json::jsonb -> 'evidence' ->> 'run_id') = (proof_json -> 'ci' ->> 'run_id')
                    AND (release_artifact_json::jsonb -> 'evidence' ->> 'completed_at_utc') =
                        (proof_json -> 'ci' ->> 'completed_at_utc')
                    AND report_publication_release_checks_match(
                        release_artifact_json::jsonb,
                        proof_json
                    ) IS TRUE
                    AND encode(sha256(convert_to(release_artifact_json, 'UTF8')), 'hex') = release_artifact_sha256
                    ) IS TRUE
                )
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX report_publications_one_active_code
                ON report_publications (code)
                WHERE status = 'published'
            SQL);

        DB::statement("ALTER TABLE report_publication_events ADD CONSTRAINT report_publication_events_type_check CHECK (event_type IN ('promoted', 'disabled'))");
        DB::statement("ALTER TABLE report_publication_events ADD CONSTRAINT report_publication_events_release_sha_check CHECK ((release_git_sha COLLATE \"C\") ~ '\\A[a-f0-9]{40}\\Z')");
        DB::statement("ALTER TABLE report_publication_events ADD CONSTRAINT report_publication_events_payload_sha_check CHECK ((payload_sha256 COLLATE \"C\") ~ '\\A[a-f0-9]{64}\\Z')");
        DB::statement("ALTER TABLE report_publication_events ADD CONSTRAINT report_publication_events_actor_check CHECK (btrim(actor_identity) <> '' AND char_length(actor_identity) <= 128)");

        DB::statement("ALTER TABLE report_publication_features ADD CONSTRAINT report_publication_features_code_check CHECK ((code COLLATE \"C\") ~ '\\A[a-z][a-z0-9_]{2,63}\\Z')");
        DB::statement("ALTER TABLE report_publication_features ADD CONSTRAINT report_publication_features_proof_sha_check CHECK ((proof_sha256 COLLATE \"C\") ~ '\\A[a-f0-9]{64}\\Z')");
        DB::statement("ALTER TABLE report_publication_features ADD CONSTRAINT report_publication_features_mode_check CHECK (mode IN ('off', 'canary', 'on', 'disabled'))");
        DB::statement(<<<'SQL'
            ALTER TABLE report_publication_features
                ADD CONSTRAINT report_publication_features_allowlist_shape_check
                CHECK (
                    (jsonb_typeof(canary_organization_ids) = 'array') IS TRUE
                    AND (jsonb_typeof(canary_user_ids) = 'array') IS TRUE
                    AND (
                        (mode = 'canary' AND (jsonb_array_length(canary_organization_ids) > 0 OR jsonb_array_length(canary_user_ids) > 0))
                        OR (mode <> 'canary' AND canary_organization_ids = '[]'::jsonb AND canary_user_ids = '[]'::jsonb)
                    )
                )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_positive_unique_ids(values_json jsonb)
            RETURNS boolean
            LANGUAGE sql
            IMMUTABLE
            STRICT
            PARALLEL SAFE
            AS $$
                SELECT jsonb_typeof(values_json) = 'array'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM jsonb_array_elements(values_json) AS ids(value)
                        WHERE jsonb_typeof(value) <> 'number'
                            OR (value #>> '{}') !~ '\A[1-9][0-9]*\Z'
                    )
                    AND (
                        SELECT count(*) = count(DISTINCT value #>> '{}')
                        FROM jsonb_array_elements(values_json) AS ids(value)
                    )
            $$;
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE report_publication_features
                ADD CONSTRAINT report_publication_features_allowlist_ids_check
                CHECK (
                    report_publication_positive_unique_ids(canary_organization_ids) IS TRUE
                    AND report_publication_positive_unique_ids(canary_user_ids) IS TRUE
                )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_reject_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'report_publication_delete_forbidden' USING ERRCODE = '55000';
                END IF;

                IF OLD.id IS DISTINCT FROM NEW.id
                    OR OLD.code IS DISTINCT FROM NEW.code
                    OR OLD.candidate_definition_json IS DISTINCT FROM NEW.candidate_definition_json
                    OR OLD.proof_json IS DISTINCT FROM NEW.proof_json
                    OR OLD.proof_sha256 IS DISTINCT FROM NEW.proof_sha256
                    OR OLD.candidate_manifest_sha256 IS DISTINCT FROM NEW.candidate_manifest_sha256
                    OR OLD.candidate_definition_sha256 IS DISTINCT FROM NEW.candidate_definition_sha256
                    OR OLD.official_manifest_sha256 IS DISTINCT FROM NEW.official_manifest_sha256
                    OR OLD.binding_sha256 IS DISTINCT FROM NEW.binding_sha256
                    OR OLD.conformance_evidence_sha256 IS DISTINCT FROM NEW.conformance_evidence_sha256
                    OR OLD.contract_version IS DISTINCT FROM NEW.contract_version
                    OR OLD.source_schema_version IS DISTINCT FROM NEW.source_schema_version
                    OR OLD.formula_version IS DISTINCT FROM NEW.formula_version
                    OR OLD.renderer_version IS DISTINCT FROM NEW.renderer_version
                    OR OLD.release_git_sha IS DISTINCT FROM NEW.release_git_sha
                    OR OLD.release_artifact_json IS DISTINCT FROM NEW.release_artifact_json
                    OR OLD.release_artifact_sha256 IS DISTINCT FROM NEW.release_artifact_sha256
                    OR OLD.release_issuer IS DISTINCT FROM NEW.release_issuer
                    OR OLD.release_key_id IS DISTINCT FROM NEW.release_key_id
                    OR OLD.published_by IS DISTINCT FROM NEW.published_by
                    OR OLD.published_at IS DISTINCT FROM NEW.published_at
                    OR OLD.status <> 'published'
                    OR NEW.status <> 'disabled'
                    OR NEW.disabled_at IS NULL
                    OR NEW.disabled_at < OLD.published_at
                    OR NEW.disabled_reason IS NULL
                    OR NEW.disabled_by IS NULL THEN
                    RAISE EXCEPTION 'report_publication_mutation_forbidden' USING ERRCODE = '55000';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER report_publications_immutable_guard
                BEFORE UPDATE OR DELETE ON report_publications
                FOR EACH ROW EXECUTE FUNCTION report_publication_reject_mutation();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_append_transition_artifacts()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                transition_type text;
                outbox_type text;
                actor_identity text;
                transition_at timestamptz;
                transition_payload jsonb;
                transition_payload_sha text;
                outbox_payload jsonb;
                feature_rows integer;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'published' THEN
                        RAISE EXCEPTION 'report_publication_initial_state_invalid' USING ERRCODE = '23514';
                    END IF;
                    IF EXISTS (
                        SELECT 1
                        FROM public.report_publications AS previous
                        WHERE previous.code = NEW.code
                            AND previous.id <> NEW.id
                            AND COALESCE(previous.disabled_at, previous.published_at) >= NEW.published_at
                    ) THEN
                        RAISE EXCEPTION 'report_publication_timestamp_not_monotonic' USING ERRCODE = '23514';
                    END IF;
                    transition_type := 'promoted';
                    outbox_type := 'report_publication_promoted';
                    actor_identity := NEW.published_by;
                    transition_at := NEW.published_at;
                    transition_payload_sha := NEW.proof_sha256;
                ELSE
                    transition_type := 'disabled';
                    outbox_type := 'report_publication_disabled';
                    actor_identity := NEW.disabled_by;
                    transition_at := NEW.disabled_at;
                    transition_payload := jsonb_build_object(
                        'actor_identity', actor_identity,
                        'disabled_at_utc', to_char(transition_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                        'publication_id', NEW.id,
                        'reason', NEW.disabled_reason
                    );
                    transition_payload_sha := encode(
                        sha256(convert_to(transition_payload::text, 'UTF8')),
                        'hex'
                    );

                    UPDATE public.report_publication_features
                    SET mode = 'disabled',
                        canary_organization_ids = '[]'::jsonb,
                        canary_user_ids = '[]'::jsonb,
                        updated_at = transition_at
                    WHERE code = NEW.code
                        AND publication_id = NEW.id
                        AND proof_sha256 = NEW.proof_sha256;
                    GET DIAGNOSTICS feature_rows = ROW_COUNT;
                    IF feature_rows <> 1 THEN
                        RAISE EXCEPTION 'report_publication_feature_transition_required' USING ERRCODE = '23514';
                    END IF;
                END IF;

                INSERT INTO public.report_publication_events (
                    id, publication_id, event_type, actor_identity,
                    release_git_sha, payload_sha256, occurred_at
                ) VALUES (
                    upper(substr(replace(gen_random_uuid()::text, '-', ''), 1, 26)),
                    NEW.id,
                    transition_type,
                    actor_identity,
                    NEW.release_git_sha,
                    transition_payload_sha,
                    transition_at
                );

                outbox_payload := jsonb_build_object(
                    'publication_id', NEW.id,
                    'payload_sha256', transition_payload_sha
                );
                INSERT INTO public.report_publication_outbox (
                    id, publication_id, event_type, deduplication_key,
                    payload_json, created_at, delivered_at
                ) VALUES (
                    upper(substr(replace(gen_random_uuid()::text, '-', ''), 1, 26)),
                    NEW.id,
                    outbox_type,
                    NEW.id || ':' || outbox_type || ':' || transition_payload_sha,
                    outbox_payload,
                    transition_at,
                    NULL
                );

                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER report_publications_transition_artifacts
                AFTER INSERT OR UPDATE ON public.report_publications
                FOR EACH ROW EXECUTE FUNCTION report_publication_append_transition_artifacts();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_require_event()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                required_type text;
                required_outbox_type text;
                required_actor text;
                required_at timestamptz;
                required_payload jsonb;
                required_payload_sha text;
                required_outbox_payload jsonb;
                required_deduplication_key text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    required_type := 'promoted';
                    required_outbox_type := 'report_publication_promoted';
                    required_actor := NEW.published_by;
                    required_at := NEW.published_at;
                    required_payload_sha := NEW.proof_sha256;
                ELSE
                    required_type := 'disabled';
                    required_outbox_type := 'report_publication_disabled';
                    required_actor := NEW.disabled_by;
                    required_at := NEW.disabled_at;
                    required_payload := jsonb_build_object(
                        'actor_identity', required_actor,
                        'disabled_at_utc', to_char(required_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                        'publication_id', NEW.id,
                        'reason', NEW.disabled_reason
                    );
                    required_payload_sha := encode(
                        sha256(convert_to(required_payload::text, 'UTF8')),
                        'hex'
                    );
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM public.report_publication_events AS event
                    WHERE event.publication_id = NEW.id
                        AND event.event_type = required_type
                        AND event.actor_identity = required_actor
                        AND event.release_git_sha = NEW.release_git_sha
                        AND event.payload_sha256 = required_payload_sha
                        AND event.occurred_at = required_at
                ) THEN
                    RAISE EXCEPTION 'report_publication_event_required' USING ERRCODE = '23514';
                END IF;

                required_outbox_payload := jsonb_build_object(
                    'publication_id', NEW.id,
                    'payload_sha256', required_payload_sha
                );
                required_deduplication_key := NEW.id || ':' || required_outbox_type || ':' || required_payload_sha;
                IF NOT EXISTS (
                    SELECT 1
                    FROM public.report_publication_outbox AS outbox
                    WHERE outbox.publication_id = NEW.id
                        AND outbox.event_type = required_outbox_type
                        AND outbox.deduplication_key = required_deduplication_key
                        AND outbox.payload_json = required_outbox_payload
                        AND outbox.created_at = required_at
                        AND outbox.delivered_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'report_publication_outbox_required' USING ERRCODE = '23514';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM public.report_publication_features AS feature
                    WHERE feature.code = NEW.code
                        AND feature.publication_id = NEW.id
                        AND feature.proof_sha256 = NEW.proof_sha256
                        AND (
                            (NEW.status = 'published' AND feature.mode <> 'disabled')
                            OR (NEW.status = 'disabled' AND feature.mode = 'disabled')
                        )
                ) THEN
                    RAISE EXCEPTION 'report_publication_feature_required' USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER report_publications_event_required
                AFTER INSERT OR UPDATE ON public.report_publications
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION report_publication_require_event();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_event_insert_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                publication public.report_publications%ROWTYPE;
                expected_payload jsonb;
                expected_payload_sha text;
            BEGIN
                SELECT * INTO publication
                FROM public.report_publications
                WHERE id = NEW.publication_id
                FOR KEY SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'report_publication_event_publication_missing' USING ERRCODE = '23503';
                END IF;

                IF NEW.event_type = 'promoted' THEN
                    expected_payload_sha := publication.proof_sha256;
                    IF publication.status <> 'published'
                        OR NEW.actor_identity <> publication.published_by
                        OR NEW.occurred_at <> publication.published_at THEN
                        RAISE EXCEPTION 'report_publication_event_state_mismatch' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    expected_payload := jsonb_build_object(
                        'actor_identity', publication.disabled_by,
                        'disabled_at_utc', to_char(publication.disabled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                        'publication_id', publication.id,
                        'reason', publication.disabled_reason
                    );
                    expected_payload_sha := encode(
                        sha256(convert_to(expected_payload::text, 'UTF8')),
                        'hex'
                    );
                    IF publication.status <> 'disabled'
                        OR NEW.actor_identity <> publication.disabled_by
                        OR NEW.occurred_at <> publication.disabled_at THEN
                        RAISE EXCEPTION 'report_publication_event_state_mismatch' USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF NEW.release_git_sha <> publication.release_git_sha
                    OR NEW.payload_sha256 <> expected_payload_sha THEN
                    RAISE EXCEPTION 'report_publication_event_payload_mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER report_publication_events_insert_guard
                BEFORE INSERT ON public.report_publication_events
                FOR EACH ROW EXECUTE FUNCTION report_publication_event_insert_guard();

            CREATE OR REPLACE FUNCTION report_publication_event_reject_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                RAISE EXCEPTION 'report_publication_events_are_append_only' USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER report_publication_events_append_only
                BEFORE UPDATE OR DELETE ON public.report_publication_events
                FOR EACH ROW EXECUTE FUNCTION report_publication_event_reject_mutation();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_feature_binding_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                publication public.report_publications%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'report_publication_feature_delete_forbidden' USING ERRCODE = '55000';
                END IF;

                SELECT * INTO publication
                FROM public.report_publications
                WHERE id = NEW.publication_id
                    AND proof_sha256 = NEW.proof_sha256
                FOR KEY SHARE NOWAIT;

                IF NOT FOUND OR publication.code <> NEW.code THEN
                    RAISE EXCEPTION 'report_publication_feature_identity_mismatch' USING ERRCODE = '23503';
                END IF;

                IF (publication.status = 'published' AND NEW.mode = 'disabled')
                    OR (publication.status <> 'published' AND NEW.mode <> 'disabled') THEN
                    RAISE EXCEPTION 'report_publication_feature_state_mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER report_publication_features_binding
                BEFORE INSERT OR UPDATE OR DELETE ON public.report_publication_features
                FOR EACH ROW EXECUTE FUNCTION report_publication_feature_binding_guard();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_append_feature_outbox()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                feature_payload jsonb;
                feature_payload_sha text;
            BEGIN
                feature_payload := jsonb_build_object(
                    'canary_organization_ids', NEW.canary_organization_ids,
                    'canary_user_ids', NEW.canary_user_ids,
                    'mode', NEW.mode,
                    'proof_sha256', NEW.proof_sha256,
                    'publication_id', NEW.publication_id
                );
                feature_payload_sha := encode(
                    sha256(convert_to(feature_payload::text, 'UTF8')),
                    'hex'
                );

                INSERT INTO public.report_publication_outbox (
                    id, publication_id, event_type, deduplication_key,
                    payload_json, created_at, delivered_at
                ) VALUES (
                    upper(substr(replace(gen_random_uuid()::text, '-', ''), 1, 26)),
                    NEW.publication_id,
                    'report_feature_configured',
                    NEW.publication_id || ':report_feature_configured:' || feature_payload_sha || ':' ||
                        to_char(NEW.updated_at AT TIME ZONE 'UTC', 'YYYYMMDDHH24MISSUS'),
                    feature_payload,
                    NEW.updated_at,
                    NULL
                );

                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER report_publication_features_outbox
                AFTER UPDATE ON public.report_publication_features
                FOR EACH ROW
                WHEN (
                    OLD.publication_id IS DISTINCT FROM NEW.publication_id
                    OR OLD.proof_sha256 IS DISTINCT FROM NEW.proof_sha256
                    OR OLD.mode IS DISTINCT FROM NEW.mode
                    OR OLD.canary_organization_ids IS DISTINCT FROM NEW.canary_organization_ids
                    OR OLD.canary_user_ids IS DISTINCT FROM NEW.canary_user_ids
                    OR OLD.updated_at IS DISTINCT FROM NEW.updated_at
                )
                EXECUTE FUNCTION report_publication_append_feature_outbox();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_outbox_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                    OR OLD.id IS DISTINCT FROM NEW.id
                    OR OLD.publication_id IS DISTINCT FROM NEW.publication_id
                    OR OLD.event_type IS DISTINCT FROM NEW.event_type
                    OR OLD.deduplication_key IS DISTINCT FROM NEW.deduplication_key
                    OR OLD.payload_json IS DISTINCT FROM NEW.payload_json
                    OR OLD.created_at IS DISTINCT FROM NEW.created_at
                    OR OLD.delivered_at IS NOT NULL
                    OR NEW.delivered_at IS NULL
                    OR NEW.delivered_at < OLD.created_at THEN
                    RAISE EXCEPTION 'report_publication_outbox_mutation_forbidden' USING ERRCODE = '55000';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER report_publication_outbox_immutable_guard
                BEFORE UPDATE OR DELETE ON public.report_publication_outbox
                FOR EACH ROW EXECUTE FUNCTION report_publication_outbox_guard();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_promote(
                p_id text,
                p_code text,
                p_candidate_definition_json jsonb,
                p_proof_json jsonb,
                p_proof_sha256 text,
                p_candidate_manifest_sha256 text,
                p_candidate_definition_sha256 text,
                p_official_manifest_sha256 text,
                p_binding_sha256 text,
                p_conformance_evidence_sha256 text,
                p_contract_version text,
                p_source_schema_version text,
                p_formula_version text,
                p_renderer_version text,
                p_release_git_sha text,
                p_release_artifact_json text,
                p_release_artifact_sha256 text,
                p_release_issuer text,
                p_release_key_id text,
                p_published_by text,
                p_published_at timestamptz
            )
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF p_published_at > clock_timestamp() THEN
                    RAISE EXCEPTION 'report_publication_release_timestamp_in_future' USING ERRCODE = '23514';
                END IF;

                PERFORM pg_advisory_xact_lock(hashtextextended('report-publication:' || p_code, 0));

                INSERT INTO public.report_publications (
                    id, code, status, candidate_definition_json, proof_json, proof_sha256,
                    candidate_manifest_sha256, candidate_definition_sha256, official_manifest_sha256,
                    binding_sha256, conformance_evidence_sha256, contract_version,
                    source_schema_version, formula_version, renderer_version, release_git_sha,
                    release_artifact_json, release_artifact_sha256, release_issuer, release_key_id,
                    published_by, published_at, disabled_at, disabled_reason, disabled_by
                ) VALUES (
                    p_id, p_code, 'published', p_candidate_definition_json, p_proof_json, p_proof_sha256,
                    p_candidate_manifest_sha256, p_candidate_definition_sha256, p_official_manifest_sha256,
                    p_binding_sha256, p_conformance_evidence_sha256, p_contract_version,
                    p_source_schema_version, p_formula_version, p_renderer_version, p_release_git_sha,
                    p_release_artifact_json, p_release_artifact_sha256, p_release_issuer, p_release_key_id,
                    p_published_by, p_published_at, NULL, NULL, NULL
                );

                INSERT INTO public.report_publication_features (
                    code, publication_id, proof_sha256, mode,
                    canary_organization_ids, canary_user_ids, updated_at
                ) VALUES (
                    p_code, p_id, p_proof_sha256, 'off', '[]'::jsonb, '[]'::jsonb, p_published_at
                )
                ON CONFLICT (code) DO UPDATE
                    SET publication_id = EXCLUDED.publication_id,
                        proof_sha256 = EXCLUDED.proof_sha256,
                        mode = EXCLUDED.mode,
                        canary_organization_ids = EXCLUDED.canary_organization_ids,
                        canary_user_ids = EXCLUDED.canary_user_ids,
                        updated_at = EXCLUDED.updated_at
                    WHERE report_publication_features.mode = 'disabled';

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'report_publication_feature_rebind_conflict' USING ERRCODE = '23514';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION report_publication_disable(
                p_publication_id text,
                p_reason text,
                p_actor_identity text
            )
            RETURNS timestamptz
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                disabled_at_value timestamptz;
            BEGIN
                UPDATE public.report_publications
                SET status = 'disabled',
                    disabled_at = GREATEST(clock_timestamp(), published_at),
                    disabled_reason = p_reason,
                    disabled_by = p_actor_identity
                WHERE id = p_publication_id
                    AND status = 'published'
                RETURNING disabled_at INTO disabled_at_value;

                IF disabled_at_value IS NULL THEN
                    RAISE EXCEPTION 'report_publication_not_active' USING ERRCODE = 'P0002';
                END IF;

                RETURN disabled_at_value;
            END;
            $$;

            CREATE OR REPLACE FUNCTION report_publication_configure_feature(
                p_code text,
                p_publication_id text,
                p_proof_sha256 text,
                p_mode text,
                p_canary_organization_ids jsonb,
                p_canary_user_ids jsonb
            )
            RETURNS timestamptz
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                configured_at timestamptz;
                current_feature public.report_publication_features%ROWTYPE;
            BEGIN
                PERFORM 1
                FROM public.report_publications
                WHERE id = p_publication_id
                    AND code = p_code
                    AND proof_sha256 = p_proof_sha256
                    AND status = 'published'
                FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'report_publication_feature_stale_identity' USING ERRCODE = 'P0002';
                END IF;

                SELECT * INTO current_feature
                FROM public.report_publication_features
                WHERE code = p_code
                    AND publication_id = p_publication_id
                    AND proof_sha256 = p_proof_sha256
                FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'report_publication_feature_stale_identity' USING ERRCODE = 'P0002';
                END IF;
                IF current_feature.mode = p_mode
                    AND current_feature.canary_organization_ids = p_canary_organization_ids
                    AND current_feature.canary_user_ids = p_canary_user_ids THEN
                    RETURN current_feature.updated_at;
                END IF;

                configured_at := GREATEST(clock_timestamp(), current_feature.updated_at);
                UPDATE public.report_publication_features
                SET mode = p_mode,
                    canary_organization_ids = p_canary_organization_ids,
                    canary_user_ids = p_canary_user_ids,
                    updated_at = configured_at
                WHERE code = p_code
                    AND publication_id = p_publication_id
                    AND proof_sha256 = p_proof_sha256;

                RETURN configured_at;
            END;
            $$;

            CREATE OR REPLACE FUNCTION report_publication_mark_outbox_delivered(
                p_outbox_id text,
                p_delivered_at timestamptz
            )
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                UPDATE public.report_publication_outbox
                SET delivered_at = p_delivered_at
                WHERE id = p_outbox_id
                    AND delivered_at IS NULL;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'report_publication_outbox_not_pending' USING ERRCODE = 'P0002';
                END IF;
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            REVOKE ALL ON TABLE report_publications, report_publication_events,
                report_publication_features, report_publication_outbox FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_positive_unique_ids(jsonb) FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_release_checks_match(jsonb, jsonb) FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_reject_mutation() FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_append_transition_artifacts() FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_require_event() FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_event_insert_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_event_reject_mutation() FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_feature_binding_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_append_feature_outbox() FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_outbox_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_promote(
                text, text, jsonb, jsonb, text, text, text, text, text, text, text,
                text, text, text, text, text, text, text, text, text, timestamptz
            ) FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_disable(text, text, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_configure_feature(text, text, text, text, jsonb, jsonb)
                FROM PUBLIC;
            REVOKE ALL ON FUNCTION report_publication_mark_outbox_delivered(text, timestamptz) FROM PUBLIC;

            GRANT SELECT ON TABLE report_publications, report_publication_features
                TO most_report_publication_runtime;
            GRANT SELECT ON TABLE report_publications, report_publication_events,
                report_publication_features, report_publication_outbox
                TO most_report_publication_issuer, most_report_publication_operator;
            GRANT SELECT ON TABLE report_publication_outbox TO most_report_publication_outbox_worker;
            GRANT EXECUTE ON FUNCTION report_publication_promote(
                text, text, jsonb, jsonb, text, text, text, text, text, text, text,
                text, text, text, text, text, text, text, text, text, timestamptz
            ) TO most_report_publication_issuer;
            GRANT EXECUTE ON FUNCTION report_publication_disable(text, text, text),
                report_publication_configure_feature(text, text, text, text, jsonb, jsonb)
                TO most_report_publication_operator;
            GRANT EXECUTE ON FUNCTION report_publication_mark_outbox_delivered(text, timestamptz)
                TO most_report_publication_outbox_worker;

            SQL);

        DB::unprepared(<<<'SQL'
            REVOKE CREATE ON SCHEMA public FROM most_report_publication_owner;
            DO $$
            BEGIN
                EXECUTE format('REVOKE most_report_publication_owner FROM %I', current_user);
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'most_report_publication_owner') THEN
                    EXECUTE format('GRANT most_report_publication_owner TO %I', current_user);
                END IF;
            END;
            $$;
            SQL);
        try {
            DB::statement('DROP FUNCTION IF EXISTS report_publication_mark_outbox_delivered(text, timestamptz)');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_configure_feature(text, text, text, text, jsonb, jsonb)');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_disable(text, text, text)');
            DB::statement(<<<'SQL'
                DROP FUNCTION IF EXISTS report_publication_promote(
                    text, text, jsonb, jsonb, text, text, text, text, text, text, text,
                    text, text, text, text, text, text, text, text, text, timestamptz
                )
                SQL);

            Schema::dropIfExists('report_publication_outbox');
            Schema::dropIfExists('report_publication_features');
            Schema::dropIfExists('report_publication_events');
            Schema::dropIfExists('report_publications');

            DB::statement('DROP FUNCTION IF EXISTS report_publication_outbox_guard()');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_append_feature_outbox()');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_feature_binding_guard()');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_require_event()');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_append_transition_artifacts()');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_event_reject_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_event_insert_guard()');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_reject_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_positive_unique_ids(jsonb)');
            DB::statement('DROP FUNCTION IF EXISTS report_publication_release_checks_match(jsonb, jsonb)');
        } finally {
            DB::unprepared(<<<'SQL'
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'most_report_publication_owner') THEN
                        REVOKE USAGE, CREATE ON SCHEMA public FROM most_report_publication_owner;
                        EXECUTE format('REVOKE most_report_publication_owner FROM %I', current_user);
                    END IF;
                END;
                $$;
                SQL);
        }
    }
};
