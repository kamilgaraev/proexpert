<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'estimate_generation_project_model_evidence_bindings';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'candidate_locator_fingerprint')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->char('candidate_locator_fingerprint', 64)->nullable()->after('candidate_value_fingerprint');
            });
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_canonical_json(value_payload jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    item jsonb;
    result jsonb;
BEGIN
    CASE jsonb_typeof(value_payload)
        WHEN 'object' THEN
            SELECT COALESCE(jsonb_object_agg(key, eg_project_model_canonical_json(value)), '{}'::jsonb)
            INTO result FROM jsonb_each(value_payload);
            RETURN result;
        WHEN 'array' THEN
            SELECT COALESCE(jsonb_agg(eg_project_model_canonical_json(value)), '[]'::jsonb)
            INTO result FROM jsonb_array_elements(value_payload) AS entry(value);
            RETURN result;
        WHEN 'number' THEN
            RETURN jsonb_build_object('number', NULLIF(trim_scale(round((value_payload #>> '{}')::numeric, 12))::text, '') );
        ELSE RETURN value_payload;
    END CASE;
END; $$;

CREATE OR REPLACE FUNCTION eg_project_model_value_fingerprint(value_payload jsonb) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF value_payload IS NULL OR jsonb_typeof(value_payload) <> 'object'
        OR NOT (value_payload ? 'value') OR NOT (value_payload ? 'unit')
        OR jsonb_typeof(value_payload->'value') <> 'number' OR jsonb_typeof(value_payload->'unit') <> 'string' THEN
        RETURN NULL;
    END IF;
    RETURN encode(digest(eg_project_model_canonical_json(value_payload)::text, 'sha256'), 'hex');
END; $$;

CREATE OR REPLACE FUNCTION eg_project_model_locator_fingerprint(locator_payload jsonb) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF locator_payload IS NULL OR jsonb_typeof(locator_payload) <> 'object' OR locator_payload = '{}'::jsonb THEN
        RETURN NULL;
    END IF;
    RETURN encode(digest(eg_project_model_canonical_json(locator_payload)::text, 'sha256'), 'hex');
END; $$;

CREATE OR REPLACE FUNCTION eg_project_model_evidence_locator_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actual_locator jsonb;
BEGIN
    SELECT locator INTO actual_locator FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id
    FOR KEY SHARE;
    IF NOT FOUND OR NEW.candidate_locator_fingerprint IS NULL
        OR NEW.candidate_locator_fingerprint !~ '^[a-f0-9]{64}$'
        OR eg_project_model_locator_fingerprint(actual_locator) <> NEW.candidate_locator_fingerprint THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_locator_invalid';
    END IF;
    RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS eg_project_model_evidence_locator_guard_trg ON estimate_generation_project_model_evidence_bindings;
CREATE TRIGGER eg_project_model_evidence_locator_guard_trg BEFORE INSERT OR UPDATE ON estimate_generation_project_model_evidence_bindings
FOR EACH ROW EXECUTE FUNCTION eg_project_model_evidence_locator_guard();
SQL);
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_locator_ck');
        DB::statement("ALTER TABLE ".self::TABLE." ADD CONSTRAINT eg_project_model_evidence_candidate_locator_ck CHECK (candidate_locator_fingerprint IS NULL OR candidate_locator_fingerprint ~ '^[a-f0-9]{64}$') NOT VALID");
        DB::statement('ALTER TABLE '.self::TABLE.' VALIDATE CONSTRAINT eg_project_model_evidence_candidate_locator_ck');
    }

    public function down(): void
    {
        if (Schema::hasColumn(self::TABLE, 'candidate_locator_fingerprint') && DB::table(self::TABLE)->whereNotNull('candidate_locator_fingerprint')->exists()) {
            throw new \RuntimeException('estimate_generation.project_model_evidence_binding_rollback_would_drop_candidate_locators');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_locator_ck');
            DB::statement('DROP TRIGGER IF EXISTS eg_project_model_evidence_locator_guard_trg ON '.self::TABLE);
            DB::statement('DROP FUNCTION IF EXISTS eg_project_model_evidence_locator_guard()');
            DB::statement('DROP FUNCTION IF EXISTS eg_project_model_locator_fingerprint(jsonb)');
            DB::statement('DROP FUNCTION IF EXISTS eg_project_model_canonical_json(jsonb)');
        }
        if (Schema::hasColumn(self::TABLE, 'candidate_locator_fingerprint')) {
            Schema::table(self::TABLE, function (Blueprint $table): void { $table->dropColumn('candidate_locator_fingerprint'); });
        }
    }
};
