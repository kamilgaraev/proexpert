<?php

declare(strict_types=1);

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
            if (! Schema::hasTable('estimate_generation_technology_planning_runs')) {
                Schema::create('estimate_generation_technology_planning_runs', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('organization_id');
                    $table->unsignedBigInteger('project_id');
                    $table->unsignedBigInteger('session_id');
                    $table->string('source_version', 71);
                    $table->string('input_fingerprint', 64);
                    $table->string('catalog_version', 64);
                    $table->string('catalog_hash', 64);
                    $table->string('result_fingerprint', 64);
                    $table->jsonb('limitations');
                    $table->boolean('is_current')->default(true);
                    $table->timestampTz('created_at')->useCurrent();
                    $table->timestampTz('invalidated_at')->nullable();
                    $table->unique(
                        ['organization_id', 'project_id', 'session_id', 'source_version', 'input_fingerprint', 'catalog_version', 'catalog_hash'],
                        'eg_tech_plan_replay_uq',
                    );
                });
            }
            if (! Schema::hasTable('estimate_generation_technology_recommendations')) {
                Schema::create('estimate_generation_technology_recommendations', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('planning_run_id')->constrained('estimate_generation_technology_planning_runs')->cascadeOnDelete();
                    $table->string('decision_key', 191);
                    $table->string('target_fact_stable_key', 191);
                    $table->text('question');
                    $table->boolean('conditional');
                    $table->jsonb('missing_facts');
                    $table->jsonb('response_options');
                    $table->timestampTz('created_at')->useCurrent();
                    $table->unique(['planning_run_id', 'decision_key'], 'eg_tech_recommendation_run_key_uq');
                });
            }
            if (! Schema::hasTable('estimate_generation_technology_recommendation_options')) {
                Schema::create('estimate_generation_technology_recommendation_options', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('recommendation_id')->constrained('estimate_generation_technology_recommendations')->cascadeOnDelete();
                    $table->string('system_id', 128);
                    $table->unsignedSmallInteger('rank');
                    $table->boolean('recommended');
                    $table->smallInteger('score');
                    $table->text('label');
                    $table->text('explanation');
                    $table->string('applicability_status', 16);
                    $table->jsonb('applicability_reasons');
                    $table->jsonb('applicability_evidence');
                    $table->jsonb('score_contributions');
                    $table->jsonb('system_payload');
                    $table->timestampTz('created_at')->useCurrent();
                    $table->unique(['recommendation_id', 'system_id'], 'eg_tech_option_system_uq');
                    $table->unique(['recommendation_id', 'rank'], 'eg_tech_option_rank_uq');
                });
            }

            $this->index($schema, 'eg_tech_plan_current_idx', 'CREATE INDEX CONCURRENTLY eg_tech_plan_current_idx ON '.$schema.'.estimate_generation_technology_planning_runs (organization_id, project_id, session_id, is_current, id DESC)');
            $this->index($schema, 'eg_tech_plan_one_current_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_tech_plan_one_current_uq ON '.$schema.'.estimate_generation_technology_planning_runs (organization_id, project_id, session_id) WHERE is_current');
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
            throw new RuntimeException('Existing technology planning index has a different definition: '.$name);
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
            throw new RuntimeException('Existing technology planning constraint has a different definition: '.$name);
        }
        if ($row === null) {
            DB::statement('ALTER TABLE '.$schema.'.'.$this->quote($table).' ADD CONSTRAINT '.$this->quote($name).' '.$expected);
        }
    }

    private function canonicalConstraint(string $definition): string
    {
        $canonical = strtolower(str_replace('"', '', $definition));
        $canonical = preg_replace('/::(?:text|character varying|bpchar)/', '', $canonical);
        $canonical = preg_replace('/\s+/', '', (string) $canonical);
        do {
            $previous = $canonical;
            $canonical = str_replace(['((', '))'], ['(', ')'], $canonical);
        } while ($canonical !== $previous);

        return $canonical;
    }

    private function constraints(string $schema): void
    {
        $this->constraint($schema, 'estimate_generation_technology_planning_runs', 'eg_tech_plan_scope_ck', <<<'SQL'
source_version ~ '^sha256:[a-f0-9]{64}$' AND input_fingerprint ~ '^[a-f0-9]{64}$'
AND catalog_hash ~ '^[a-f0-9]{64}$' AND result_fingerprint ~ '^[a-f0-9]{64}$'
AND jsonb_typeof(limitations) = 'array' AND octet_length(limitations::text) <= 32768
AND ((is_current AND invalidated_at IS NULL) OR (NOT is_current AND invalidated_at IS NOT NULL))
SQL);
        $this->constraint($schema, 'estimate_generation_technology_recommendations', 'eg_tech_recommendation_payload_ck', <<<'SQL'
decision_key ~ '^[a-z][a-z0-9._:-]{1,190}$' AND target_fact_stable_key ~ '^[a-z][a-z0-9._:-]{1,190}$'
AND length(btrim(question)) BETWEEN 1 AND 4000
AND jsonb_typeof(missing_facts) = 'array' AND octet_length(missing_facts::text) <= 32768
AND jsonb_typeof(response_options) = 'array' AND octet_length(response_options::text) <= 32768
SQL);
        $this->constraint($schema, 'estimate_generation_technology_recommendation_options', 'eg_tech_option_payload_ck', <<<'SQL'
system_id ~ '^[a-z][a-z0-9._-]{2,127}$' AND rank BETWEEN 1 AND 4 AND score BETWEEN -1000 AND 1000
AND length(btrim(label)) BETWEEN 1 AND 2000 AND length(btrim(explanation)) BETWEEN 1 AND 4000
AND applicability_status IN ('applicable', 'conditional', 'unavailable')
AND jsonb_typeof(applicability_reasons) = 'array' AND octet_length(applicability_reasons::text) <= 32768
AND jsonb_typeof(applicability_evidence) = 'array' AND octet_length(applicability_evidence::text) <= 65536
AND jsonb_typeof(score_contributions) = 'array' AND octet_length(score_contributions::text) <= 65536
AND jsonb_typeof(system_payload) = 'object' AND octet_length(system_payload::text) <= 262144
SQL);
    }

    private function guards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_technology_planning_run_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'technology planning history is immutable';
    END IF;
    IF ROW(NEW.organization_id, NEW.project_id, NEW.session_id, NEW.source_version, NEW.input_fingerprint,
        NEW.catalog_version, NEW.catalog_hash, NEW.result_fingerprint, NEW.limitations, NEW.created_at)
       IS DISTINCT FROM
       ROW(OLD.organization_id, OLD.project_id, OLD.session_id, OLD.source_version, OLD.input_fingerprint,
        OLD.catalog_version, OLD.catalog_hash, OLD.result_fingerprint, OLD.limitations, OLD.created_at) THEN
        RAISE EXCEPTION 'technology planning immutable fields cannot change';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS eg_technology_planning_run_guard_trg ON estimate_generation_technology_planning_runs;
CREATE TRIGGER eg_technology_planning_run_guard_trg BEFORE UPDATE OR DELETE ON estimate_generation_technology_planning_runs FOR EACH ROW EXECUTE FUNCTION eg_technology_planning_run_guard();
DROP TRIGGER IF EXISTS eg_technology_recommendation_guard_trg ON estimate_generation_technology_recommendations;
CREATE TRIGGER eg_technology_recommendation_guard_trg BEFORE UPDATE OR DELETE ON estimate_generation_technology_recommendations FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP TRIGGER IF EXISTS eg_technology_option_guard_trg ON estimate_generation_technology_recommendation_options;
CREATE TRIGGER eg_technology_option_guard_trg BEFORE UPDATE OR DELETE ON estimate_generation_technology_recommendation_options FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Technology planning projections keep immutable history and have no destructive rollback.');
    }
};
