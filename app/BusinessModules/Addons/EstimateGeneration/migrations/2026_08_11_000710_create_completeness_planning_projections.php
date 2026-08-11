<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Database\PostgresCheckConstraintDefinition;
use App\Contracts\Database\ForwardOnlyMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration implements ForwardOnlyMigration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $schema = $this->schema();
        try {
            $this->configureSession($schema);
            if (! Schema::hasTable('estimate_generation_completeness_runs')) {
                Schema::create('estimate_generation_completeness_runs', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('organization_id');
                    $table->unsignedBigInteger('project_id');
                    $table->unsignedBigInteger('session_id');
                    $table->string('source_version', 71);
                    $table->string('input_fingerprint', 64);
                    $table->string('catalog_version', 64);
                    $table->string('catalog_hash', 64);
                    $table->string('rule_catalog_version', 64);
                    $table->string('rule_catalog_hash', 64);
                    $table->string('result_fingerprint', 64);
                    $table->jsonb('limitations');
                    $table->boolean('is_current')->default(true);
                    $table->timestampTz('created_at')->useCurrent();
                    $table->timestampTz('invalidated_at')->nullable();
                    $table->unique([
                        'organization_id', 'project_id', 'session_id', 'source_version', 'input_fingerprint',
                        'catalog_version', 'catalog_hash', 'rule_catalog_version', 'rule_catalog_hash',
                    ], 'eg_completeness_replay_uq');
                });
            }
            if (! Schema::hasTable('estimate_generation_completeness_findings')) {
                Schema::create('estimate_generation_completeness_findings', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('completeness_run_id')->constrained('estimate_generation_completeness_runs')->cascadeOnDelete();
                    $table->string('rule_id', 128);
                    $table->string('rule_version', 64);
                    $table->string('rule_hash', 64);
                    $table->string('finding_stable_key', 64);
                    $table->unsignedSmallInteger('finding_version');
                    $table->string('classification', 32);
                    $table->string('status', 32);
                    $table->string('severity', 24);
                    $table->text('impact');
                    $table->decimal('confidence', 5, 4);
                    $table->jsonb('evidence_fact_ids');
                    $table->jsonb('related_entity_ids');
                    $table->jsonb('related_fact_types');
                    $table->jsonb('applicability');
                    $table->jsonb('exclusion_policy');
                    $table->jsonb('exclusion_decision')->nullable();
                    $table->timestampTz('created_at')->useCurrent();
                    $table->unique(['completeness_run_id', 'rule_id'], 'eg_completeness_finding_rule_uq');
                });
            }
            if (! Schema::hasTable('estimate_generation_technology_work_packages')) {
                Schema::create('estimate_generation_technology_work_packages', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('completeness_finding_id')->constrained('estimate_generation_completeness_findings')->cascadeOnDelete();
                    $table->string('stable_key', 191);
                    $table->jsonb('works');
                    $table->jsonb('materials');
                    $table->jsonb('machinery');
                    $table->jsonb('norm_intents');
                    $table->jsonb('quantity_formulas');
                    $table->jsonb('dependencies');
                    $table->jsonb('regional_price_availability');
                    $table->jsonb('assumptions');
                    $table->jsonb('risks');
                    $table->jsonb('provenance');
                    $table->timestampTz('created_at')->useCurrent();
                    $table->unique('completeness_finding_id', 'eg_work_package_finding_uq');
                });
            }
            $this->index($schema, 'eg_completeness_current_idx', 'CREATE INDEX CONCURRENTLY eg_completeness_current_idx ON '.$schema.'.estimate_generation_completeness_runs (organization_id, project_id, session_id, is_current, id DESC)');
            $this->index($schema, 'eg_completeness_one_current_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_completeness_one_current_uq ON '.$schema.'.estimate_generation_completeness_runs (organization_id, project_id, session_id) WHERE is_current');
            $this->constraints($schema);
            $this->guards();
        } finally {
            $this->resetSession();
        }
    }

    private function index(string $schema, string $name, string $sql): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT index_state.indisvalid, index_state.indisready, pg_get_indexdef(index_state.indexrelid) AS definition
FROM pg_index AS index_state
JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid
JOIN pg_namespace AS index_schema ON index_schema.oid = index_class.relnamespace
WHERE index_schema.nspname = ? AND index_class.relname = ?
SQL, [$this->unquote($schema), $name]);
        if ($row !== null && (! filter_var($row->indisvalid, FILTER_VALIDATE_BOOL)
            || ! filter_var($row->indisready, FILTER_VALIDATE_BOOL))) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$schema.'.'.$this->quote($name));
            $row = null;
        }
        if ($row !== null && $this->canonicalIndex((string) $row->definition, $schema) !== $this->canonicalIndex($sql, $schema)) {
            throw new RuntimeException('Existing completeness index has a different definition: '.$name);
        }
        if ($row === null) {
            DB::statement($sql);
        }
    }

    private function schema(): string
    {
        $configured = config('database.connections.'.config('database.default').'.schema');
        $name = is_string($configured) && trim($configured) !== ''
            ? trim(explode(',', $configured)[0])
            : (string) (DB::selectOne('SELECT current_schema() AS schema_name')->schema_name ?? '');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new RuntimeException('PostgreSQL schema is invalid.');
        }

        return $this->quote($name);
    }

    private function configureSession(string $schema): void
    {
        DB::statement("SET lock_timeout TO '5s'");
        DB::statement("SET statement_timeout TO '5min'");
        DB::statement('SET search_path TO '.$schema);
    }

    private function resetSession(): void
    {
        DB::statement('RESET lock_timeout');
        DB::statement('RESET statement_timeout');
        DB::statement('RESET search_path');
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function unquote(string $identifier): string
    {
        return str_replace('""', '"', trim($identifier, '"'));
    }

    private function canonicalIndex(string $definition, string $schema): string
    {
        $canonical = strtolower(str_replace('"', '', $definition));
        $canonical = str_replace(strtolower($this->unquote($schema)).'.', '', $canonical);
        $canonical = preg_replace('/\s+/', ' ', trim($canonical));
        $canonical = str_replace(' concurrently ', ' ', (string) $canonical);

        return str_replace(' using btree ', ' ', (string) $canonical);
    }

    private function constraint(string $schema, string $table, string $name, string $check): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(constraint_state.oid, true) AS definition
FROM pg_constraint AS constraint_state
JOIN pg_class AS constraint_table ON constraint_table.oid = constraint_state.conrelid
JOIN pg_namespace AS constraint_schema ON constraint_schema.oid = constraint_table.relnamespace
WHERE constraint_schema.nspname = ? AND constraint_table.relname = ?
  AND constraint_state.conname = ? AND constraint_state.contype = 'c'
SQL, [$this->unquote($schema), $table, $name]);
        $expected = 'CHECK ('.$check.')';
        if ($row !== null && $this->canonicalConstraint((string) $row->definition) !== $this->canonicalConstraint($expected)) {
            throw new RuntimeException('Existing completeness constraint has a different definition: '.$name);
        }
        if ($row === null) {
            DB::statement('ALTER TABLE '.$schema.'.'.$this->quote($table).' ADD CONSTRAINT '.$this->quote($name).' '.$expected);
        }
    }

    private function canonicalConstraint(string $definition): string
    {
        return PostgresCheckConstraintDefinition::canonical($definition);
    }

    private function constraints(string $schema): void
    {
        $this->constraint($schema, 'estimate_generation_completeness_runs', 'eg_completeness_run_ck', <<<'SQL'
source_version ~ '^sha256:[a-f0-9]{64}$' AND input_fingerprint ~ '^[a-f0-9]{64}$'
AND catalog_hash ~ '^[a-f0-9]{64}$' AND rule_catalog_hash ~ '^[a-f0-9]{64}$'
AND result_fingerprint ~ '^[a-f0-9]{64}$' AND jsonb_typeof(limitations) = 'array'
AND octet_length(limitations::text) <= 32768
AND ((is_current AND invalidated_at IS NULL) OR (NOT is_current AND invalidated_at IS NOT NULL))
SQL);
        $this->constraint($schema, 'estimate_generation_completeness_findings', 'eg_completeness_finding_ck', <<<'SQL'
finding_stable_key ~ '^[a-f0-9]{64}$' AND finding_version BETWEEN 1 AND 65535
AND classification IN ('document_missing', 'technology_required', 'optional_recommendation', 'technology_conditional', 'not_applicable')
AND status IN ('unknown', 'unresolved', 'proven_missing', 'satisfied', 'not_applicable', 'excluded')
AND confidence BETWEEN 0 AND 1 AND octet_length(evidence_fact_ids::text) <= 65536
AND octet_length(related_entity_ids::text) <= 32768 AND octet_length(related_fact_types::text) <= 32768
AND jsonb_typeof(applicability) = 'object' AND octet_length(applicability::text) <= 65536
AND octet_length(exclusion_policy::text) <= 32768
AND (exclusion_decision IS NULL OR octet_length(exclusion_decision::text) <= 32768)
SQL);
        $this->constraint($schema, 'estimate_generation_technology_work_packages', 'eg_work_package_payload_ck', <<<'SQL'
octet_length(works::text) <= 262144 AND octet_length(materials::text) <= 262144
AND octet_length(machinery::text) <= 131072 AND octet_length(norm_intents::text) <= 131072
AND octet_length(quantity_formulas::text) <= 131072 AND octet_length(dependencies::text) <= 65536
AND octet_length(regional_price_availability::text) <= 32768
AND octet_length(assumptions::text) <= 65536 AND octet_length(risks::text) <= 65536
AND octet_length(provenance::text) <= 65536
SQL);
    }

    private function guards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_completeness_run_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'completeness history is immutable'; END IF;
    IF ROW(NEW.organization_id, NEW.project_id, NEW.session_id, NEW.source_version, NEW.input_fingerprint,
        NEW.catalog_version, NEW.catalog_hash, NEW.rule_catalog_version, NEW.rule_catalog_hash,
        NEW.result_fingerprint, NEW.limitations, NEW.created_at)
       IS DISTINCT FROM ROW(OLD.organization_id, OLD.project_id, OLD.session_id, OLD.source_version, OLD.input_fingerprint,
        OLD.catalog_version, OLD.catalog_hash, OLD.rule_catalog_version, OLD.rule_catalog_hash,
        OLD.result_fingerprint, OLD.limitations, OLD.created_at) THEN
        RAISE EXCEPTION 'completeness immutable fields cannot change';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS eg_completeness_run_guard_trg ON estimate_generation_completeness_runs;
CREATE TRIGGER eg_completeness_run_guard_trg BEFORE UPDATE OR DELETE ON estimate_generation_completeness_runs FOR EACH ROW EXECUTE FUNCTION eg_completeness_run_guard();
DROP TRIGGER IF EXISTS eg_completeness_finding_guard_trg ON estimate_generation_completeness_findings;
CREATE TRIGGER eg_completeness_finding_guard_trg BEFORE UPDATE OR DELETE ON estimate_generation_completeness_findings FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP TRIGGER IF EXISTS eg_work_package_guard_trg ON estimate_generation_technology_work_packages;
CREATE TRIGGER eg_work_package_guard_trg BEFORE UPDATE OR DELETE ON estimate_generation_technology_work_packages FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Completeness projections keep immutable history and have no destructive rollback.');
    }
};
