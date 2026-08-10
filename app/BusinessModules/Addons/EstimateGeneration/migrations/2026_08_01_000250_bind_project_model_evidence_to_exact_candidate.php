<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Migrations\Support\OnlineSchemaMigrationRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/support/OnlineSchemaMigrationRuntime.php';

return new class extends Migration
{
    private const TABLE = 'estimate_generation_project_model_evidence_bindings';

    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
            $this->replaceExactBindingGuard();
            $this->ensureExactBindingGuardTrigger();
        }

        $this->ensureColumns();

        if (DB::getDriverName() !== 'pgsql') {
            $this->ensureNonPostgresIndexesAndForeignKeys();

            return;
        }

        $runtime = new OnlineSchemaMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            $this->assertNoInvalidExactBindingRows();
            $this->ensurePostgresIndexes($runtime);
            $this->ensurePostgresConstraints($runtime);
            $this->validateExactBindingConstraints($runtime);
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS eg_project_model_evidence_binding_uq');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertNoExactBindingAuditData();
            $this->dropNonPostgresSchema();

            return;
        }

        $this->assertNoExactBindingAuditData();

        $runtime = new OnlineSchemaMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            $runtime->ensureConcurrentIndex(
                'eg_project_model_evidence_binding_uq',
                'CREATE UNIQUE INDEX CONCURRENTLY eg_project_model_evidence_binding_uq ON '.self::TABLE.' (entity_id, evidence_id)'
            );
            if (! $this->postgresConstraintExists('eg_project_model_evidence_binding_uq')) {
                DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT eg_project_model_evidence_binding_uq UNIQUE USING INDEX eg_project_model_evidence_binding_uq');
            }
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_project_model_evidence_candidate_binding_uq');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_project_model_evidence_correction_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_project_model_evidence_assertion_idx');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
            $this->assertNoExactBindingAuditData();
            $this->replaceLegacyBindingGuard();
            DB::statement('DROP FUNCTION IF EXISTS eg_project_model_value_fingerprint(jsonb)');

            DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_project_model_evidence_bindings
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_fingerprint_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_source_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_subject_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_version_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_correction_scope_fk,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_assertion_scope_fk;
SQL);

            $this->dropColumns();
        });
    }

    private function ensureColumns(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'assertion_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('assertion_id')->nullable()->after('entity_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'correction_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('correction_id')->nullable()->after('assertion_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'candidate_source')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('candidate_source', 32)->nullable()->after('evidence_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'candidate_value_fingerprint')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->char('candidate_value_fingerprint', 64)->nullable()->after('candidate_source');
            });
        }
    }

    private function ensureNonPostgresIndexesAndForeignKeys(): void
    {
        if (! Schema::hasIndex(self::TABLE, 'eg_project_model_evidence_assertion_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['assertion_id', 'building_model_id'], 'eg_project_model_evidence_assertion_idx');
            });
        }
        if (! Schema::hasIndex(self::TABLE, 'eg_project_model_evidence_correction_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['correction_id', 'building_model_id'], 'eg_project_model_evidence_correction_idx');
            });
        }
        if (! Schema::hasIndex(self::TABLE, 'eg_project_model_evidence_candidate_binding_uq')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['assertion_id', 'correction_id', 'evidence_id'], 'eg_project_model_evidence_candidate_binding_uq');
            });
        }
        if (! $this->foreignKeyExists('eg_project_model_evidence_assertion_scope_fk')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign(['assertion_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_evidence_assertion_scope_fk')
                    ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on('estimate_generation_project_model_assertions')
                    ->cascadeOnDelete();
            });
        }
        if (! $this->foreignKeyExists('eg_project_model_evidence_correction_scope_fk')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign(['correction_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_evidence_correction_scope_fk')
                    ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on('estimate_generation_project_model_corrections')
                    ->cascadeOnDelete();
            });
        }
    }

    private function ensurePostgresIndexes(OnlineSchemaMigrationRuntime $runtime): void
    {
        $runtime->ensureConcurrentIndex(
            'eg_project_model_evidence_assertion_idx',
            'CREATE INDEX CONCURRENTLY eg_project_model_evidence_assertion_idx ON '.self::TABLE.' (assertion_id, building_model_id)'
        );
        $runtime->ensureConcurrentIndex(
            'eg_project_model_evidence_correction_idx',
            'CREATE INDEX CONCURRENTLY eg_project_model_evidence_correction_idx ON '.self::TABLE.' (correction_id, building_model_id)'
        );
        $runtime->ensureConcurrentIndex(
            'eg_project_model_evidence_candidate_binding_uq',
            'CREATE UNIQUE INDEX CONCURRENTLY eg_project_model_evidence_candidate_binding_uq ON '.self::TABLE.' (COALESCE(assertion_id, 0), COALESCE(correction_id, 0), evidence_id) WHERE num_nonnulls(assertion_id, correction_id) = 1'
        );
    }

    private function ensurePostgresConstraints(OnlineSchemaMigrationRuntime $runtime): void
    {
        $runtime->ensureConstraint(
            self::TABLE,
            'eg_project_model_evidence_assertion_scope_fk',
            'FOREIGN KEY (assertion_id, building_model_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_assertions (id, building_model_id, organization_id, project_id, session_id, source_version) ON DELETE CASCADE'
        );
        $runtime->ensureConstraint(
            self::TABLE,
            'eg_project_model_evidence_correction_scope_fk',
            'FOREIGN KEY (correction_id, building_model_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_corrections (id, building_model_id, organization_id, project_id, session_id, source_version) ON DELETE CASCADE'
        );
        $runtime->ensureConstraint(
            self::TABLE,
            'eg_project_model_evidence_candidate_subject_ck',
            'CHECK ((assertion_id IS NOT NULL AND correction_id IS NULL) OR (assertion_id IS NULL AND correction_id IS NOT NULL))'
        );
        $runtime->ensureConstraint(
            self::TABLE,
            'eg_project_model_evidence_candidate_source_ck',
            "CHECK (candidate_source IS NOT NULL AND candidate_source IN ('manual_correction', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry'))"
        );
        $runtime->ensureConstraint(
            self::TABLE,
            'eg_project_model_evidence_candidate_fingerprint_ck',
            "CHECK (candidate_value_fingerprint IS NOT NULL AND candidate_value_fingerprint ~ '^[a-f0-9]{64}$')"
        );
        $runtime->ensureConstraint(
            self::TABLE,
            'eg_project_model_evidence_candidate_version_ck',
            "CHECK (source_version IS NOT NULL AND source_version ~ '^sha256:[a-f0-9]{64}$' AND evidence_source_version IS NOT NULL AND length(btrim(evidence_source_version)) > 0 AND evidence_invalidation_version IS NOT NULL AND evidence_invalidation_version >= 0)"
        );
    }

    private function validateExactBindingConstraints(OnlineSchemaMigrationRuntime $runtime): void
    {
        foreach ([
            'eg_project_model_evidence_assertion_scope_fk',
            'eg_project_model_evidence_correction_scope_fk',
            'eg_project_model_evidence_candidate_subject_ck',
            'eg_project_model_evidence_candidate_source_ck',
            'eg_project_model_evidence_candidate_fingerprint_ck',
            'eg_project_model_evidence_candidate_version_ck',
        ] as $constraint) {
            $runtime->validateConstraint(self::TABLE, $constraint);
        }
    }

    private function assertNoInvalidExactBindingRows(): void
    {
        if (! $this->hasAllExactAuditColumns()) {
            throw new RuntimeException('estimate_generation.project_model_evidence_binding_incomplete_audit_schema');
        }

        $invalid = DB::selectOne(<<<'SQL'
SELECT binding.id
FROM estimate_generation_project_model_evidence_bindings binding
LEFT JOIN estimate_generation_evidence evidence
    ON evidence.id = binding.evidence_id
    AND evidence.organization_id = binding.organization_id
    AND evidence.project_id = binding.project_id
    AND evidence.session_id = binding.session_id
LEFT JOIN estimate_generation_building_model_evidence model_evidence
    ON model_evidence.building_model_id = binding.building_model_id
    AND model_evidence.evidence_id = binding.evidence_id
    AND model_evidence.organization_id = binding.organization_id
    AND model_evidence.project_id = binding.project_id
    AND model_evidence.session_id = binding.session_id
LEFT JOIN estimate_generation_project_model_entities entity
    ON entity.id = binding.entity_id
    AND entity.building_model_id = binding.building_model_id
    AND entity.organization_id = binding.organization_id
    AND entity.project_id = binding.project_id
    AND entity.session_id = binding.session_id
    AND entity.source_version = binding.source_version
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
WHERE NOT COALESCE(
    evidence.id IS NOT NULL
    AND evidence.invalidated_at IS NULL
    AND evidence.source_version = binding.evidence_source_version
    AND evidence.invalidation_version = binding.evidence_invalidation_version
    AND model_evidence.evidence_id IS NOT NULL
    AND entity.id IS NOT NULL
    AND ((binding.assertion_id IS NOT NULL AND binding.correction_id IS NULL) OR (binding.assertion_id IS NULL AND binding.correction_id IS NOT NULL))
    AND binding.candidate_source IN ('manual_correction', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry')
    AND binding.candidate_value_fingerprint ~ '^[a-f0-9]{64}$'
    AND evidence.source_ref ~ '^document:[1-9][0-9]*$'
    AND (evidence.locator->>'document_id')::text = split_part(evidence.source_ref, ':', 2)
    AND binding.source_version ~ '^sha256:[a-f0-9]{64}$'
    AND length(btrim(binding.evidence_source_version)) > 0
    AND binding.evidence_invalidation_version >= 0
    AND (
        (
            binding.assertion_id IS NOT NULL
            AND assertion.entity_id = binding.entity_id
            AND assertion.payload->>'source' = binding.candidate_source
            AND eg_project_model_value_fingerprint(assertion.payload - 'source') = binding.candidate_value_fingerprint
        )
        OR (
            binding.correction_id IS NOT NULL
            AND correction_assertion.entity_id = binding.entity_id
            AND (CASE correction.correction_type WHEN 'manual' THEN 'manual_correction' WHEN 'source_reconciliation' THEN 'reconciled_geometry' END) = binding.candidate_source
            AND eg_project_model_value_fingerprint(correction_assertion.payload - 'source') = binding.candidate_value_fingerprint
        )
    ), false)
ORDER BY binding.id
LIMIT 1
SQL);

        if ($invalid !== null) {
            throw new RuntimeException('estimate_generation.project_model_evidence_binding_incomplete_audit:'.$invalid->id);
        }
    }

    private function hasAllExactAuditColumns(): bool
    {
        foreach (['assertion_id', 'correction_id', 'candidate_source', 'candidate_value_fingerprint'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                return false;
            }
        }

        return true;
    }

    private function assertNoExactBindingAuditData(): void
    {
        $auditColumns = ['assertion_id', 'correction_id', 'candidate_source', 'candidate_value_fingerprint'];
        if (array_filter($auditColumns, static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)) === []) {
            return;
        }

        $hasAuditData = DB::table(self::TABLE)->where(function ($query): void {
            if (Schema::hasColumn(self::TABLE, 'assertion_id')) {
                $query->whereNotNull('assertion_id');
            }
            if (Schema::hasColumn(self::TABLE, 'correction_id')) {
                $query->orWhereNotNull('correction_id');
            }
            if (Schema::hasColumn(self::TABLE, 'candidate_source')) {
                $query->orWhereNotNull('candidate_source');
            }
            if (Schema::hasColumn(self::TABLE, 'candidate_value_fingerprint')) {
                $query->orWhereNotNull('candidate_value_fingerprint');
            }
        })->exists();

        if ($hasAuditData) {
            throw new RuntimeException('estimate_generation.project_model_evidence_binding_rollback_would_drop_candidate_bindings');
        }
    }

    private function dropNonPostgresSchema(): void
    {
        if ($this->foreignKeyExists('eg_project_model_evidence_correction_scope_fk')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign('eg_project_model_evidence_correction_scope_fk');
            });
        }
        if ($this->foreignKeyExists('eg_project_model_evidence_assertion_scope_fk')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign('eg_project_model_evidence_assertion_scope_fk');
            });
        }
        if (Schema::hasIndex(self::TABLE, 'eg_project_model_evidence_candidate_binding_uq')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique('eg_project_model_evidence_candidate_binding_uq');
            });
        }
        if (Schema::hasIndex(self::TABLE, 'eg_project_model_evidence_correction_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex('eg_project_model_evidence_correction_idx');
            });
        }
        if (Schema::hasIndex(self::TABLE, 'eg_project_model_evidence_assertion_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex('eg_project_model_evidence_assertion_idx');
            });
        }
        $this->dropColumns();
    }

    private function dropColumns(): void
    {
        $columns = array_values(array_filter([
            'candidate_value_fingerprint',
            'candidate_source',
            'correction_id',
            'assertion_id',
        ], static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)));

        if ($columns !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function foreignKeyExists(string $name): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function postgresConstraintExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? AND t.relname = ? AND c.conname = ?',
            ['public', self::TABLE, $name],
        ) !== null;
    }

    private function replaceExactBindingGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_value_fingerprint(value_payload jsonb) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    normalized_number text;
BEGIN
    IF value_payload IS NULL OR jsonb_typeof(value_payload) <> 'object'
        OR NOT (value_payload ? 'value') OR NOT (value_payload ? 'unit')
        OR jsonb_typeof(value_payload->'value') <> 'number' OR jsonb_typeof(value_payload->'unit') <> 'string' THEN
        RETURN NULL;
    END IF;
    normalized_number := rtrim(rtrim(to_char((value_payload->>'value')::numeric, 'FM999999999999999999999999990D00000000000000000'), '0'), '.');
    RETURN encode(digest(format('{"unit":%s,"value":{"number":%s}}', to_jsonb(value_payload->>'unit')::text, to_jsonb(normalized_number)::text), 'sha256'), 'hex');
END; $$;

CREATE OR REPLACE FUNCTION eg_project_model_evidence_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    binding_row jsonb := to_jsonb(NEW);
    exact_column_count integer;
    actual_source_version text;
    actual_invalidation_version integer;
    actual_invalidated_at timestamptz;
    binding_assertion_id bigint;
    binding_correction_id bigint;
    binding_candidate_source text;
    binding_candidate_value_fingerprint text;
    assertion_entity_id bigint;
    assertion_source text;
    correction_assertion_id bigint;
    correction_source text;
    candidate_payload jsonb;
    evidence_value jsonb;
    evidence_locator jsonb;
    evidence_type text;
    evidence_source_type text;
    evidence_source_ref text;
    evidence_producer_name text;
    evidence_producer_version text;
BEGIN
    SELECT source_version, invalidation_version, invalidated_at, value, locator, type, source_type, source_ref, producer_name, producer_version
    INTO actual_source_version, actual_invalidation_version, actual_invalidated_at, evidence_value, evidence_locator, evidence_type, evidence_source_type, evidence_source_ref, evidence_producer_name, evidence_producer_version
    FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id
    FOR UPDATE;
    IF NOT FOUND OR actual_invalidated_at IS NOT NULL OR actual_source_version <> NEW.evidence_source_version OR actual_invalidation_version <> NEW.evidence_invalidation_version THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    PERFORM 1 FROM estimate_generation_building_model_evidence
    WHERE building_model_id = NEW.building_model_id AND evidence_id = NEW.evidence_id
      AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    PERFORM 1 FROM estimate_generation_project_model_entities
    WHERE id = NEW.entity_id
      AND building_model_id = NEW.building_model_id
      AND organization_id = NEW.organization_id
      AND project_id = NEW.project_id
      AND session_id = NEW.session_id
      AND source_version = NEW.source_version;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
    END IF;

    SELECT count(*) INTO exact_column_count
    FROM pg_attribute
    WHERE attrelid = TG_RELID
      AND attname = ANY (ARRAY['assertion_id', 'correction_id', 'candidate_source', 'candidate_value_fingerprint'])
      AND NOT attisdropped;
    IF exact_column_count = 0 THEN
        RETURN NEW;
    END IF;
    IF exact_column_count <> 4 THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_binding_partial_schema';
    END IF;

    binding_assertion_id := NULLIF(binding_row->>'assertion_id', '')::bigint;
    binding_correction_id := NULLIF(binding_row->>'correction_id', '')::bigint;
    binding_candidate_source := binding_row->>'candidate_source';
    binding_candidate_value_fingerprint := binding_row->>'candidate_value_fingerprint';
    IF num_nonnulls(binding_assertion_id, binding_correction_id) <> 1
        OR binding_candidate_source IS NULL OR binding_candidate_value_fingerprint IS NULL THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
    END IF;
    IF binding_candidate_source NOT IN ('manual_correction', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry')
        OR binding_candidate_value_fingerprint !~ '^[a-f0-9]{64}$'
        OR NEW.source_version IS NULL OR NEW.source_version !~ '^sha256:[a-f0-9]{64}$'
        OR NEW.evidence_source_version IS NULL OR btrim(NEW.evidence_source_version) = ''
        OR NEW.evidence_invalidation_version IS NULL OR NEW.evidence_invalidation_version < 0 THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
    END IF;
    IF binding_assertion_id IS NOT NULL THEN
        SELECT entity_id, payload->>'source', payload - 'source' INTO assertion_entity_id, assertion_source, candidate_payload
        FROM estimate_generation_project_model_assertions
        WHERE id = binding_assertion_id
          AND building_model_id = NEW.building_model_id
          AND organization_id = NEW.organization_id
          AND project_id = NEW.project_id
          AND session_id = NEW.session_id
          AND source_version = NEW.source_version;
        IF NOT FOUND OR assertion_entity_id <> NEW.entity_id OR assertion_source IS NULL OR assertion_source <> binding_candidate_source
            OR eg_project_model_value_fingerprint(candidate_payload) <> binding_candidate_value_fingerprint THEN
            RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
        END IF;
    ELSE
        SELECT assertion_id, CASE correction_type WHEN 'manual' THEN 'manual_correction' WHEN 'source_reconciliation' THEN 'reconciled_geometry' END
        INTO correction_assertion_id, correction_source
        FROM estimate_generation_project_model_corrections
        WHERE id = binding_correction_id
          AND building_model_id = NEW.building_model_id
          AND organization_id = NEW.organization_id
          AND project_id = NEW.project_id
          AND session_id = NEW.session_id
          AND source_version = NEW.source_version;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
        END IF;
        SELECT entity_id, payload - 'source' INTO assertion_entity_id, candidate_payload
        FROM estimate_generation_project_model_assertions
        WHERE id = correction_assertion_id
          AND building_model_id = NEW.building_model_id
          AND organization_id = NEW.organization_id
          AND project_id = NEW.project_id
          AND session_id = NEW.session_id
          AND source_version = NEW.source_version;
        IF NOT FOUND OR assertion_entity_id <> NEW.entity_id OR correction_source IS NULL OR correction_source <> binding_candidate_source
            OR eg_project_model_value_fingerprint(candidate_payload) <> binding_candidate_value_fingerprint THEN
            RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
        END IF;
    END IF;
    IF NOT (
        evidence_source_ref ~ '^document:[1-9][0-9]*$'
        AND (evidence_locator->>'document_id')::text = split_part(evidence_source_ref, ':', 2)
        AND (evidence_locator->>'unit_index') ~ '^[0-9]+$'
        AND (evidence_locator->>'page') = (evidence_locator->>'unit_index')
        AND (
            (binding_candidate_source = 'explicit_dimension'
                AND evidence_type = 'extracted' AND evidence_source_type = 'document_unit'
                AND evidence_producer_name = 'drawing_analyzer' AND evidence_producer_version = 'model:v2'
                AND evidence_value->>'field_key' = 'room_area' AND evidence_value->>'unit' = 'm2'
                AND (evidence_value->>'field_value')::numeric > 0
                AND evidence_locator ? 'region_key' AND evidence_locator ? 'element_key' AND evidence_locator ? 'bbox'
                AND candidate_payload = jsonb_build_object('unit', 'm2', 'value', (evidence_value->>'field_value')::numeric))
            OR (binding_candidate_source = 'table'
                AND evidence_type = 'extracted' AND evidence_source_type = 'document_unit'
                AND evidence_producer_name = 'ocr_fact_extractor' AND evidence_producer_version = 'extractor:v1'
                AND evidence_value->>'field_key' = 'room_area' AND evidence_value->>'unit' = 'm2'
                AND (evidence_value->>'field_value')::numeric > 0 AND evidence_locator ? 'cell'
                AND candidate_payload = jsonb_build_object('unit', 'm2', 'value', (evidence_value->>'field_value')::numeric))
            OR (binding_candidate_source IN ('cad', 'reconciled_geometry')
                AND evidence_type = 'measured' AND evidence_source_type = 'document_unit'
                AND evidence_producer_name = 'pdf_geometry' AND evidence_producer_version = 'extractor:v1'
                AND evidence_value->>'field_key' = 'dimension_value' AND evidence_value ? 'unit'
                AND (evidence_value->>'field_value')::numeric > 0 AND evidence_locator ? 'handle'
                AND candidate_payload = jsonb_build_object('unit', evidence_value->>'unit', 'value', (evidence_value->>'field_value')::numeric))
        )
    ) THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
    END IF;
    RETURN NEW;
END; $$;
SQL);
    }

    private function replaceLegacyBindingGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_evidence_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    actual_source_version text;
    actual_invalidation_version integer;
    actual_invalidated_at timestamptz;
BEGIN
    SELECT source_version, invalidation_version, invalidated_at
    INTO actual_source_version, actual_invalidation_version, actual_invalidated_at
    FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id
    FOR UPDATE;
    IF NOT FOUND OR actual_invalidated_at IS NOT NULL OR actual_source_version <> NEW.evidence_source_version OR actual_invalidation_version <> NEW.evidence_invalidation_version THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    PERFORM 1 FROM estimate_generation_building_model_evidence
    WHERE building_model_id = NEW.building_model_id AND evidence_id = NEW.evidence_id
      AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    RETURN NEW;
END; $$;
SQL);
    }

    private function ensureExactBindingGuardTrigger(): void
    {
        $trigger = DB::selectOne(
            'SELECT pg_get_triggerdef(t.oid, true) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? AND t.tgname = ? AND NOT t.tgisinternal',
            ['public', self::TABLE, 'eg_project_model_evidence_binding_guard_trg'],
        );

        if ($trigger === null) {
            DB::statement('CREATE TRIGGER eg_project_model_evidence_binding_guard_trg BEFORE INSERT ON '.self::TABLE.' FOR EACH ROW EXECUTE FUNCTION eg_project_model_evidence_binding_guard()');

            return;
        }

        $definition = strtolower((string) $trigger->definition);
        if (! str_contains($definition, 'before insert on '.self::TABLE) || ! str_contains($definition, 'execute function eg_project_model_evidence_binding_guard()')) {
            throw new RuntimeException('estimate_generation.project_model_evidence_binding_guard_trigger_definition_mismatch');
        }
    }
};
