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
                $table->jsonb('score_contributions');
                $table->jsonb('system_payload');
                $table->timestampTz('created_at')->useCurrent();
                $table->unique(['recommendation_id', 'system_id'], 'eg_tech_option_system_uq');
                $table->unique(['recommendation_id', 'rank'], 'eg_tech_option_rank_uq');
            });
        }

        $this->index('eg_tech_plan_current_idx', 'CREATE INDEX CONCURRENTLY eg_tech_plan_current_idx ON estimate_generation_technology_planning_runs (organization_id, project_id, session_id, is_current, id DESC)');
        $this->index('eg_tech_plan_one_current_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_tech_plan_one_current_uq ON estimate_generation_technology_planning_runs (organization_id, project_id, session_id) WHERE is_current');
        $this->constraints();
        $this->guards();
    }

    private function index(string $name, string $sql): void
    {
        $exists = DB::selectOne('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?', [$name]);
        if ($exists === null) {
            DB::statement($sql);
        }
    }

    private function constraints(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_tech_plan_scope_ck') THEN
        ALTER TABLE estimate_generation_technology_planning_runs ADD CONSTRAINT eg_tech_plan_scope_ck CHECK (
            source_version ~ '^sha256:[a-f0-9]{64}$' AND input_fingerprint ~ '^[a-f0-9]{64}$'
            AND catalog_hash ~ '^[a-f0-9]{64}$' AND result_fingerprint ~ '^[a-f0-9]{64}$'
            AND jsonb_typeof(limitations) = 'array' AND octet_length(limitations::text) <= 32768
            AND ((is_current AND invalidated_at IS NULL) OR (NOT is_current AND invalidated_at IS NOT NULL))
        );
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_tech_recommendation_payload_ck') THEN
        ALTER TABLE estimate_generation_technology_recommendations ADD CONSTRAINT eg_tech_recommendation_payload_ck CHECK (
            decision_key ~ '^[a-z][a-z0-9._:-]{1,190}$' AND target_fact_stable_key ~ '^[a-z][a-z0-9._:-]{1,190}$'
            AND length(btrim(question)) BETWEEN 1 AND 4000
            AND jsonb_typeof(missing_facts) = 'array' AND octet_length(missing_facts::text) <= 32768
            AND jsonb_typeof(response_options) = 'array' AND octet_length(response_options::text) <= 32768
        );
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_tech_option_payload_ck') THEN
        ALTER TABLE estimate_generation_technology_recommendation_options ADD CONSTRAINT eg_tech_option_payload_ck CHECK (
            system_id ~ '^[a-z][a-z0-9._-]{2,127}$' AND rank BETWEEN 1 AND 4 AND score BETWEEN -1000 AND 1000
            AND length(btrim(label)) BETWEEN 1 AND 2000 AND length(btrim(explanation)) BETWEEN 1 AND 4000
            AND jsonb_typeof(score_contributions) = 'array' AND octet_length(score_contributions::text) <= 65536
            AND jsonb_typeof(system_payload) = 'object' AND octet_length(system_payload::text) <= 262144
        );
    END IF;
END $$;
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
