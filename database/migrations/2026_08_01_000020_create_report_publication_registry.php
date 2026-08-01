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
        ] as $column) {
            DB::statement("ALTER TABLE report_publications ADD CONSTRAINT report_publications_{$column}_check CHECK (({$column} COLLATE \"C\") ~ '\\A[a-f0-9]{64}\\Z')");
        }
        DB::statement("ALTER TABLE report_publications ADD CONSTRAINT report_publications_release_sha_check CHECK ((release_git_sha COLLATE \"C\") ~ '\\A[a-f0-9]{40}\\Z')");
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
        DB::statement(<<<'SQL'
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
                    AND ((proof_json -> 'versions' ->> 'renderer') = renderer_version) IS TRUE
                    AND ((proof_json -> 'release' ->> 'git_sha') = release_git_sha) IS TRUE
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
                        FROM report_publications AS previous
                        WHERE previous.code = NEW.code
                            AND previous.id <> NEW.id
                            AND previous.published_at >= NEW.published_at
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

                    UPDATE report_publication_features
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

                INSERT INTO report_publication_events (
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
                INSERT INTO report_publication_outbox (
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
                AFTER INSERT OR UPDATE ON report_publications
                FOR EACH ROW EXECUTE FUNCTION report_publication_append_transition_artifacts();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_require_event()
            RETURNS trigger
            LANGUAGE plpgsql
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
                    FROM report_publication_events AS event
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
                    FROM report_publication_outbox AS outbox
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
                    FROM report_publication_features AS feature
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
                AFTER INSERT OR UPDATE ON report_publications
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION report_publication_require_event();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_event_insert_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                publication report_publications%ROWTYPE;
                expected_payload jsonb;
                expected_payload_sha text;
            BEGIN
                SELECT * INTO publication
                FROM report_publications
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
                BEFORE INSERT ON report_publication_events
                FOR EACH ROW EXECUTE FUNCTION report_publication_event_insert_guard();

            CREATE OR REPLACE FUNCTION report_publication_event_reject_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'report_publication_events_are_append_only' USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER report_publication_events_append_only
                BEFORE UPDATE OR DELETE ON report_publication_events
                FOR EACH ROW EXECUTE FUNCTION report_publication_event_reject_mutation();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_feature_binding_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                publication report_publications%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'report_publication_feature_delete_forbidden' USING ERRCODE = '55000';
                END IF;

                SELECT * INTO publication
                FROM report_publications
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
                BEFORE INSERT OR UPDATE OR DELETE ON report_publication_features
                FOR EACH ROW EXECUTE FUNCTION report_publication_feature_binding_guard();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_publication_append_feature_outbox()
            RETURNS trigger
            LANGUAGE plpgsql
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

                INSERT INTO report_publication_outbox (
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
                AFTER UPDATE ON report_publication_features
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
                BEFORE UPDATE OR DELETE ON report_publication_outbox
                FOR EACH ROW EXECUTE FUNCTION report_publication_outbox_guard();
            SQL);
    }

    public function down(): void
    {
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
    }
};
