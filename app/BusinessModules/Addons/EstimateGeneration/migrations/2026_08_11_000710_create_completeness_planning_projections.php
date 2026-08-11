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
                $table->string('classification', 32);
                $table->string('status', 32);
                $table->string('severity', 24);
                $table->text('impact');
                $table->decimal('confidence', 5, 4);
                $table->jsonb('evidence_fact_ids');
                $table->jsonb('related_entity_ids');
                $table->jsonb('related_fact_types');
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
        $this->index('eg_completeness_current_idx', 'CREATE INDEX CONCURRENTLY eg_completeness_current_idx ON estimate_generation_completeness_runs (organization_id, project_id, session_id, is_current, id DESC)');
        $this->index('eg_completeness_one_current_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_completeness_one_current_uq ON estimate_generation_completeness_runs (organization_id, project_id, session_id) WHERE is_current');
        $this->constraints();
        $this->guards();
    }

    private function index(string $name, string $sql): void
    {
        if (DB::selectOne('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?', [$name]) === null) {
            DB::statement($sql);
        }
    }

    private function constraints(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_completeness_run_ck') THEN
        ALTER TABLE estimate_generation_completeness_runs ADD CONSTRAINT eg_completeness_run_ck CHECK (
            source_version ~ '^sha256:[a-f0-9]{64}$' AND input_fingerprint ~ '^[a-f0-9]{64}$'
            AND catalog_hash ~ '^[a-f0-9]{64}$' AND rule_catalog_hash ~ '^[a-f0-9]{64}$'
            AND result_fingerprint ~ '^[a-f0-9]{64}$' AND jsonb_typeof(limitations) = 'array'
            AND octet_length(limitations::text) <= 32768
            AND ((is_current AND invalidated_at IS NULL) OR (NOT is_current AND invalidated_at IS NOT NULL))
        );
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_completeness_finding_ck') THEN
        ALTER TABLE estimate_generation_completeness_findings ADD CONSTRAINT eg_completeness_finding_ck CHECK (
            classification IN ('document_missing', 'technology_required', 'optional_recommendation', 'not_applicable')
            AND status IN ('unknown', 'proven_missing', 'satisfied', 'not_applicable', 'excluded')
            AND confidence BETWEEN 0 AND 1 AND octet_length(evidence_fact_ids::text) <= 65536
            AND octet_length(related_entity_ids::text) <= 32768 AND octet_length(related_fact_types::text) <= 32768
            AND octet_length(exclusion_policy::text) <= 32768
            AND (exclusion_decision IS NULL OR octet_length(exclusion_decision::text) <= 32768)
        );
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'eg_work_package_payload_ck') THEN
        ALTER TABLE estimate_generation_technology_work_packages ADD CONSTRAINT eg_work_package_payload_ck CHECK (
            octet_length(works::text) <= 262144 AND octet_length(materials::text) <= 262144
            AND octet_length(machinery::text) <= 131072 AND octet_length(norm_intents::text) <= 131072
            AND octet_length(quantity_formulas::text) <= 131072 AND octet_length(dependencies::text) <= 65536
            AND octet_length(regional_price_availability::text) <= 32768
            AND octet_length(assumptions::text) <= 65536 AND octet_length(risks::text) <= 65536
            AND octet_length(provenance::text) <= 65536
        );
    END IF;
END $$;
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
