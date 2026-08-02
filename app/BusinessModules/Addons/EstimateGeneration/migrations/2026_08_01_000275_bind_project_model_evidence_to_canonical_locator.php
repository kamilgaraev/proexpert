<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Support\TrainingBenchmarkOnlineMigrationRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'estimate_generation_project_model_evidence_bindings';

    public $withinTransaction = false;

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

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
            $this->installLegacyValueFingerprint();
            $this->replaceCanonicalFingerprintFunctions();
            $runtime->checkpoint('project_model_locator.functions_replaced');
            $this->backfillCanonicalBindingFingerprints($runtime);
            $this->ensureLocatorGuardTrigger();
            $runtime->ensureConstraint(
                self::TABLE,
                'eg_project_model_evidence_candidate_locator_ck',
                "CHECK (candidate_locator_fingerprint IS NOT NULL AND candidate_locator_fingerprint ~ '^[a-f0-9]{64}$')",
            );
            $runtime->validateConstraint(self::TABLE, 'eg_project_model_evidence_candidate_locator_ck');
            $this->assertCanonicalLocatorBindings();
            DB::statement('DROP FUNCTION IF EXISTS eg_project_model_legacy_value_fingerprint(jsonb)');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
            $timeouts = $runtime->configureSessionTimeouts();
            try {
                DB::transaction(function () use ($runtime): void {
                    DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
                    $runtime->checkpoint('project_model_locator.down.locked');
                    if (DB::table(self::TABLE)->whereNotNull('candidate_locator_fingerprint')->exists()) {
                        throw new \RuntimeException('estimate_generation.project_model_evidence_binding_rollback_would_drop_candidate_locators');
                    }
                    DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_locator_ck');
                    DB::statement('DROP TRIGGER IF EXISTS eg_project_model_evidence_locator_guard_trg ON '.self::TABLE);
                    DB::statement('DROP FUNCTION IF EXISTS eg_project_model_evidence_locator_guard()');
                    $this->restoreExactBindingValueFingerprint();
                    DB::statement('DROP FUNCTION IF EXISTS eg_project_model_locator_fingerprint(jsonb)');
                    DB::statement('DROP FUNCTION IF EXISTS eg_project_model_canonical_json(jsonb)');
                    DB::statement('DROP FUNCTION IF EXISTS eg_project_model_legacy_value_fingerprint(jsonb)');
                    DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN IF EXISTS candidate_locator_fingerprint');
                    $runtime->checkpoint('project_model_locator.down.column_dropped');
                });
            } finally {
                $runtime->restoreSessionTimeouts($timeouts);
            }
        } elseif (Schema::hasColumn(self::TABLE, 'candidate_locator_fingerprint')) {
            if (DB::table(self::TABLE)->whereNotNull('candidate_locator_fingerprint')->exists()) {
                throw new \RuntimeException('estimate_generation.project_model_evidence_binding_rollback_would_drop_candidate_locators');
            }
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('candidate_locator_fingerprint');
            });
        }
    }

    private function backfillCanonicalBindingFingerprints(TrainingBenchmarkOnlineMigrationRuntime $runtime): void
    {
        DB::transaction(function () use ($runtime): void {
            DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
            $runtime->checkpoint('project_model_locator.backfill.locked');
            $this->assertBackfillableBindings();

            DB::statement('ALTER TABLE '.self::TABLE.' DISABLE TRIGGER eg_project_model_evidence_binding_append_trg');
            DB::statement('ALTER TABLE '.self::TABLE.' DISABLE TRIGGER eg_project_model_evidence_binding_guard_trg');
            try {
                DB::unprepared(<<<'SQL'
UPDATE estimate_generation_project_model_evidence_bindings binding
SET candidate_value_fingerprint = eg_project_model_value_fingerprint(
        CASE WHEN binding.assertion_id IS NOT NULL THEN assertion.payload - 'source' ELSE correction_assertion.payload - 'source' END
    ),
    candidate_locator_fingerprint = eg_project_model_locator_fingerprint(evidence.locator)
FROM estimate_generation_evidence evidence
LEFT JOIN estimate_generation_project_model_assertions assertion
    ON assertion.id = binding.assertion_id
    AND assertion.building_model_id = binding.building_model_id
    AND assertion.organization_id = binding.organization_id
    AND assertion.project_id = binding.project_id
    AND assertion.session_id = binding.session_id
    AND assertion.source_version = binding.source_version
LEFT JOIN estimate_generation_project_model_corrections correction
    ON correction.id = binding.correction_id
    AND correction.building_model_id = binding.building_model_id
    AND correction.organization_id = binding.organization_id
    AND correction.project_id = binding.project_id
    AND correction.session_id = binding.session_id
    AND correction.source_version = binding.source_version
LEFT JOIN estimate_generation_project_model_assertions correction_assertion
    ON correction_assertion.id = correction.assertion_id
    AND correction_assertion.building_model_id = binding.building_model_id
    AND correction_assertion.organization_id = binding.organization_id
    AND correction_assertion.project_id = binding.project_id
    AND correction_assertion.session_id = binding.session_id
    AND correction_assertion.source_version = binding.source_version
WHERE evidence.id = binding.evidence_id
  AND evidence.organization_id = binding.organization_id
  AND evidence.project_id = binding.project_id
  AND evidence.session_id = binding.session_id
SQL);
            } finally {
                DB::statement('ALTER TABLE '.self::TABLE.' ENABLE TRIGGER eg_project_model_evidence_binding_guard_trg');
                DB::statement('ALTER TABLE '.self::TABLE.' ENABLE TRIGGER eg_project_model_evidence_binding_append_trg');
            }

            $this->assertCanonicalLocatorBindings();
            $runtime->checkpoint('project_model_locator.backfill.completed');
        });
    }

    private function assertBackfillableBindings(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
SELECT binding.id
FROM estimate_generation_project_model_evidence_bindings binding
LEFT JOIN estimate_generation_evidence evidence
    ON evidence.id = binding.evidence_id
    AND evidence.organization_id = binding.organization_id
    AND evidence.project_id = binding.project_id
    AND evidence.session_id = binding.session_id
LEFT JOIN estimate_generation_project_model_assertions assertion
    ON assertion.id = binding.assertion_id
    AND assertion.building_model_id = binding.building_model_id
    AND assertion.organization_id = binding.organization_id
    AND assertion.project_id = binding.project_id
    AND assertion.session_id = binding.session_id
    AND assertion.source_version = binding.source_version
LEFT JOIN estimate_generation_project_model_corrections correction
    ON correction.id = binding.correction_id
    AND correction.building_model_id = binding.building_model_id
    AND correction.organization_id = binding.organization_id
    AND correction.project_id = binding.project_id
    AND correction.session_id = binding.session_id
    AND correction.source_version = binding.source_version
LEFT JOIN estimate_generation_project_model_assertions correction_assertion
    ON correction_assertion.id = correction.assertion_id
    AND correction_assertion.building_model_id = binding.building_model_id
    AND correction_assertion.organization_id = binding.organization_id
    AND correction_assertion.project_id = binding.project_id
    AND correction_assertion.session_id = binding.session_id
    AND correction_assertion.source_version = binding.source_version
WHERE evidence.id IS NULL
   OR evidence.invalidated_at IS NOT NULL
   OR evidence.source_version <> binding.evidence_source_version
   OR evidence.invalidation_version <> binding.evidence_invalidation_version
   OR evidence.locator IS NULL
   OR jsonb_typeof(evidence.locator) <> 'object'
   OR evidence.locator = '{}'::jsonb
   OR num_nonnulls(binding.assertion_id, binding.correction_id) <> 1
   OR (binding.assertion_id IS NOT NULL AND (
        assertion.id IS NULL OR assertion.entity_id <> binding.entity_id
        OR assertion.payload->>'source' <> binding.candidate_source
        OR (binding.candidate_value_fingerprint IS DISTINCT FROM eg_project_model_legacy_value_fingerprint(assertion.payload - 'source')
            AND binding.candidate_value_fingerprint IS DISTINCT FROM eg_project_model_value_fingerprint(assertion.payload - 'source'))
   ))
   OR (binding.correction_id IS NOT NULL AND (
        correction.id IS NULL OR correction_assertion.id IS NULL OR correction_assertion.entity_id <> binding.entity_id
        OR (CASE correction.correction_type WHEN 'manual' THEN 'manual_correction' WHEN 'source_reconciliation' THEN 'reconciled_geometry' END) <> binding.candidate_source
        OR (binding.candidate_value_fingerprint IS DISTINCT FROM eg_project_model_legacy_value_fingerprint(correction_assertion.payload - 'source')
            AND binding.candidate_value_fingerprint IS DISTINCT FROM eg_project_model_value_fingerprint(correction_assertion.payload - 'source'))
   ))
ORDER BY binding.id
LIMIT 1
SQL);

        if ($invalid !== null) {
            throw new \RuntimeException('estimate_generation.project_model_evidence_binding_requires_review:'.$invalid->id);
        }
    }

    private function assertCanonicalLocatorBindings(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
SELECT binding.id
FROM estimate_generation_project_model_evidence_bindings binding
JOIN estimate_generation_evidence evidence
    ON evidence.id = binding.evidence_id
    AND evidence.organization_id = binding.organization_id
    AND evidence.project_id = binding.project_id
    AND evidence.session_id = binding.session_id
WHERE binding.candidate_locator_fingerprint IS NULL
   OR binding.candidate_locator_fingerprint !~ '^[a-f0-9]{64}$'
   OR binding.candidate_locator_fingerprint <> eg_project_model_locator_fingerprint(evidence.locator)
ORDER BY binding.id
LIMIT 1
SQL);

        if ($invalid !== null) {
            throw new \RuntimeException('estimate_generation.project_model_evidence_binding_canonical_locator_invalid:'.$invalid->id);
        }
    }

    private function replaceCanonicalFingerprintFunctions(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_canonical_json(value_payload jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE result jsonb;
BEGIN
    CASE jsonb_typeof(value_payload)
        WHEN 'object' THEN SELECT COALESCE(jsonb_object_agg(key, eg_project_model_canonical_json(value)), '{}'::jsonb) INTO result FROM jsonb_each(value_payload); RETURN result;
        WHEN 'array' THEN SELECT COALESCE(jsonb_agg(eg_project_model_canonical_json(value)), '[]'::jsonb) INTO result FROM jsonb_array_elements(value_payload) AS entry(value); RETURN result;
        WHEN 'number' THEN RETURN jsonb_build_object('number', NULLIF(trim_scale(round((value_payload #>> '{}')::numeric, 12))::text, ''));
        ELSE RETURN value_payload;
    END CASE;
END; $$;

CREATE OR REPLACE FUNCTION eg_project_model_value_fingerprint(value_payload jsonb) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF value_payload IS NULL OR jsonb_typeof(value_payload) <> 'object'
        OR NOT (value_payload ? 'value') OR NOT (value_payload ? 'unit')
        OR jsonb_typeof(value_payload->'value') <> 'number' OR jsonb_typeof(value_payload->'unit') <> 'string' THEN RETURN NULL; END IF;
    RETURN encode(digest(eg_project_model_canonical_json(value_payload)::text, 'sha256'), 'hex');
END; $$;

CREATE OR REPLACE FUNCTION eg_project_model_locator_fingerprint(locator_payload jsonb) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF locator_payload IS NULL OR jsonb_typeof(locator_payload) <> 'object' OR locator_payload = '{}'::jsonb THEN RETURN NULL; END IF;
    RETURN encode(digest(eg_project_model_canonical_json(locator_payload)::text, 'sha256'), 'hex');
END; $$;
SQL);
    }

    private function installLegacyValueFingerprint(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_legacy_value_fingerprint(value_payload jsonb) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE normalized_number text;
BEGIN
    IF value_payload IS NULL OR jsonb_typeof(value_payload) <> 'object'
        OR NOT (value_payload ? 'value') OR NOT (value_payload ? 'unit')
        OR jsonb_typeof(value_payload->'value') <> 'number' OR jsonb_typeof(value_payload->'unit') <> 'string' THEN RETURN NULL; END IF;
    normalized_number := rtrim(rtrim(to_char((value_payload->>'value')::numeric, 'FM999999999999999999999999990D00000000000000000'), '0'), '.');
    RETURN encode(digest(format('{"unit":%s,"value":{"number":%s}}', to_jsonb(value_payload->>'unit')::text, to_jsonb(normalized_number)::text), 'sha256'), 'hex');
END; $$;
SQL);
    }

    private function restoreExactBindingValueFingerprint(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_value_fingerprint(value_payload jsonb) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE normalized_number text;
BEGIN
    IF value_payload IS NULL OR jsonb_typeof(value_payload) <> 'object'
        OR NOT (value_payload ? 'value') OR NOT (value_payload ? 'unit')
        OR jsonb_typeof(value_payload->'value') <> 'number' OR jsonb_typeof(value_payload->'unit') <> 'string' THEN RETURN NULL; END IF;
    normalized_number := rtrim(rtrim(to_char((value_payload->>'value')::numeric, 'FM999999999999999999999999990D00000000000000000'), '0'), '.');
    RETURN encode(digest(format('{"unit":%s,"value":{"number":%s}}', to_jsonb(value_payload->>'unit')::text, to_jsonb(normalized_number)::text), 'sha256'), 'hex');
END; $$;
SQL);
    }

    private function ensureLocatorGuardTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_evidence_locator_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actual_locator jsonb;
BEGIN
    SELECT locator INTO actual_locator FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id FOR KEY SHARE;
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
    }
};
