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
        $this->ensureColumns();

        if (DB::getDriverName() !== 'pgsql') {
            $this->ensureNonPostgresIndexesAndForeignKeys();

            return;
        }

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            $this->ensurePostgresIndexes($runtime);
            $this->ensurePostgresConstraints($runtime);
            $this->replaceExactBindingGuard();
            $this->ensureExactBindingGuardTrigger();
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS eg_project_model_evidence_binding_uq');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        $this->assertNoExactBindingAuditData();

        if (DB::getDriverName() !== 'pgsql') {
            $this->dropNonPostgresSchema();

            return;
        }

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
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

        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_project_model_evidence_bindings
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_fingerprint_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_source_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_subject_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_correction_scope_fk,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_assertion_scope_fk;
SQL);

        $this->dropColumns();
        $this->replaceLegacyBindingGuard();
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

    private function ensurePostgresIndexes(TrainingBenchmarkOnlineMigrationRuntime $runtime): void
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

    private function ensurePostgresConstraints(TrainingBenchmarkOnlineMigrationRuntime $runtime): void
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
            'CHECK (num_nonnulls(assertion_id, correction_id) = 1)'
        );
        $runtime->ensureConstraint(
            self::TABLE,
            'eg_project_model_evidence_candidate_source_ck',
            "CHECK (candidate_source IN ('manual_correction', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry'))"
        );
        $runtime->ensureConstraint(
            self::TABLE,
            'eg_project_model_evidence_candidate_fingerprint_ck',
            "CHECK (candidate_value_fingerprint ~ '^[a-f0-9]{64}$')"
        );
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
CREATE OR REPLACE FUNCTION eg_project_model_evidence_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    actual_source_version text;
    actual_invalidation_version integer;
    actual_invalidated_at timestamptz;
    assertion_entity_id bigint;
    assertion_source text;
    correction_assertion_id bigint;
    correction_source text;
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
    IF num_nonnulls(NEW.assertion_id, NEW.correction_id) <> 1
        OR NEW.candidate_source IS NULL OR NEW.candidate_value_fingerprint IS NULL THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
    END IF;
    IF NEW.assertion_id IS NOT NULL THEN
        SELECT entity_id, payload->>'source' INTO assertion_entity_id, assertion_source
        FROM estimate_generation_project_model_assertions WHERE id = NEW.assertion_id;
        IF NOT FOUND OR assertion_entity_id <> NEW.entity_id OR assertion_source <> NEW.candidate_source THEN
            RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
        END IF;
    ELSE
        SELECT assertion_id, CASE correction_type WHEN 'manual' THEN 'manual_correction' WHEN 'source_reconciliation' THEN 'reconciled_geometry' END
        INTO correction_assertion_id, correction_source
        FROM estimate_generation_project_model_corrections WHERE id = NEW.correction_id;
        SELECT entity_id INTO assertion_entity_id FROM estimate_generation_project_model_assertions WHERE id = correction_assertion_id;
        IF NOT FOUND OR assertion_entity_id <> NEW.entity_id OR correction_source <> NEW.candidate_source THEN
            RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
        END IF;
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
