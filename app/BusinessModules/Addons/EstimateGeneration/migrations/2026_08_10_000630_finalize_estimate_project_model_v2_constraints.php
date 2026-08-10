<?php

declare(strict_types=1);

use App\Contracts\Database\ForwardOnlyMigration;
use App\Support\Database\PostgresSchemaIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration implements ForwardOnlyMigration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        try {
            $schema = (string) DB::selectOne('SELECT current_schema() AS schema_name')->schema_name;
            DB::statement('SET search_path TO '.PostgresSchemaIdentifier::quote($schema).', pg_catalog');
            DB::statement("SET lock_timeout TO '5s'");
            DB::statement("SET statement_timeout TO '15min'");
            $this->concurrentIndex('eg_pm_fact_projection_scope_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_pm_fact_projection_scope_uq ON estimate_generation_project_model_fact_projections (id, organization_id, project_id, session_id, source_version)');
            $this->concurrentIndex('eg_pm_conflict_scope_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_pm_conflict_scope_uq ON estimate_generation_project_model_conflicts (id, organization_id, project_id, session_id, source_version)');
            $this->concurrentIndex('eg_pm_derived_scope_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_pm_derived_scope_uq ON estimate_generation_project_model_derived_quantities (id, organization_id, project_id, session_id, source_version)');
            $this->concurrentIndex('eg_pm_cross_link_scope_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_pm_cross_link_scope_uq ON estimate_generation_project_model_cross_document_links (id, organization_id, project_id, session_id, source_version)');

            DB::statement('ALTER TABLE estimate_generation_project_model_corrections ALTER COLUMN actor_id DROP NOT NULL');
            $this->constraint('estimate_generation_project_model_corrections', 'eg_pm_decision_actor_type_ck', "CHECK (decision_actor_type IN ('user','system')) NOT VALID");
            $this->constraint('estimate_generation_project_model_corrections', 'eg_pm_decision_actor_ck', "CHECK ((decision_actor_type = 'user' AND actor_id IS NOT NULL AND system_actor_key IS NULL) OR (decision_actor_type = 'system' AND actor_id IS NULL AND length(btrim(system_actor_key)) > 0)) NOT VALID");
            $this->constraint('estimate_generation_project_model_corrections', 'eg_pm_decision_version_ck', 'CHECK (decision_version > 0) NOT VALID');
            $this->constraint('estimate_generation_project_model_fact_evidence', 'eg_pm_fact_evidence_fact_fk', 'FOREIGN KEY (fact_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version) NOT VALID');
            $this->constraint('estimate_generation_project_model_fact_projections', 'eg_pm_fact_projection_fact_fk', 'FOREIGN KEY (fact_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version) NOT VALID');
            $this->constraint('estimate_generation_project_model_conflict_facts', 'eg_pm_conflict_facts_fact_fk', 'FOREIGN KEY (fact_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version) NOT VALID');
            $this->constraint('estimate_generation_project_model_derived_operands', 'eg_pm_derived_operands_fact_fk', 'FOREIGN KEY (fact_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version) NOT VALID');
            $this->constraint('estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_left_fact_fk', 'FOREIGN KEY (left_fact_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version) NOT VALID');
            $this->constraint('estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_right_fact_fk', 'FOREIGN KEY (right_fact_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version) NOT VALID');

            $this->installGuards();
            foreach ([
                ['estimate_generation_project_model_entities', 'eg_pm_entities_kind_v2_ck'],
                ['estimate_generation_project_model_assertions', 'eg_pm_fact_origin_ck'],
                ['estimate_generation_project_model_assertions', 'eg_pm_fact_status_ck'],
                ['estimate_generation_project_model_assertions', 'eg_pm_fact_version_ck'],
                ['estimate_generation_project_model_assertions', 'eg_pm_fact_value_size_ck'],
                ['estimate_generation_project_model_corrections', 'eg_pm_decision_actor_type_ck'],
                ['estimate_generation_project_model_corrections', 'eg_pm_decision_actor_ck'],
                ['estimate_generation_project_model_corrections', 'eg_pm_decision_version_ck'],
                ['estimate_generation_project_model_fact_projections', 'eg_pm_fact_projection_source_ck'],
                ['estimate_generation_project_model_fact_projections', 'eg_pm_fact_projection_version_ck'],
                ['estimate_generation_project_model_fact_projections', 'eg_pm_fact_projection_current_ck'],
                ['estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_status_ck'],
                ['estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_distinct_ck'],
                ['estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_strategy_ck'],
                ['estimate_generation_project_model_cross_link_evidence', 'eg_pm_cross_link_evidence_side_ck'],
                ['estimate_generation_project_model_conflicts', 'eg_pm_conflict_status_ck'],
                ['estimate_generation_project_model_conflicts', 'eg_pm_conflict_reason_ck'],
                ['estimate_generation_project_model_conflicts', 'eg_pm_conflict_version_ck'],
                ['estimate_generation_project_model_derived_quantities', 'eg_pm_derived_status_ck'],
                ['estimate_generation_project_model_derived_quantities', 'eg_pm_derived_rounding_ck'],
                ['estimate_generation_project_model_derived_quantities', 'eg_pm_derived_evidence_ck'],
                ['estimate_generation_project_model_fact_evidence', 'eg_pm_fact_evidence_fact_fk'],
                ['estimate_generation_project_model_fact_projections', 'eg_pm_fact_projection_fact_fk'],
                ['estimate_generation_project_model_conflict_facts', 'eg_pm_conflict_facts_fact_fk'],
                ['estimate_generation_project_model_derived_operands', 'eg_pm_derived_operands_fact_fk'],
                ['estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_left_fact_fk'],
                ['estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_right_fact_fk'],
            ] as [$table, $constraint]) {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            }
        } finally {
            try {
                DB::statement('RESET lock_timeout');
            } finally {
                try {
                    DB::statement('RESET statement_timeout');
                } finally {
                    DB::statement('RESET search_path');
                }
            }
        }
    }

    private function concurrentIndex(string $name, string $sql): void
    {
        $state = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
        if ($state !== null && ! (bool) $state->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
            $state = null;
        }
        if ($state === null) {
            DB::statement($sql);
        }
    }

    private function constraint(string $table, string $name, string $definition): void
    {
        if (DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$name, $table]) === null) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_pm_fact_evidence_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM estimate_generation_evidence WHERE id = NEW.evidence_id
      AND organization_id = NEW.organization_id AND project_id = NEW.project_id
      AND session_id = NEW.session_id AND source_version = NEW.evidence_source_version
      AND invalidation_version = NEW.evidence_invalidation_version AND invalidated_at IS NULL;
    IF NOT FOUND THEN RAISE EXCEPTION 'estimate_generation.project_model_fact_evidence_scope_invalid'; END IF;
    RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS eg_pm_fact_evidence_scope_trg ON estimate_generation_project_model_fact_evidence;
CREATE TRIGGER eg_pm_fact_evidence_scope_trg BEFORE INSERT ON estimate_generation_project_model_fact_evidence FOR EACH ROW EXECUTE FUNCTION eg_pm_fact_evidence_scope_guard();

CREATE OR REPLACE FUNCTION eg_pm_cross_link_evidence_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM estimate_generation_evidence WHERE id = NEW.evidence_id
      AND organization_id = NEW.organization_id AND project_id = NEW.project_id
      AND session_id = NEW.session_id AND invalidated_at IS NULL;
    IF NOT FOUND THEN RAISE EXCEPTION 'estimate_generation.project_model_cross_link_evidence_scope_invalid'; END IF;
    RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS eg_pm_cross_link_evidence_scope_trg ON estimate_generation_project_model_cross_link_evidence;
CREATE TRIGGER eg_pm_cross_link_evidence_scope_trg BEFORE INSERT ON estimate_generation_project_model_cross_link_evidence FOR EACH ROW EXECUTE FUNCTION eg_pm_cross_link_evidence_scope_guard();

DROP TRIGGER IF EXISTS eg_pm_conflict_append_trg ON estimate_generation_project_model_conflicts;
CREATE TRIGGER eg_pm_conflict_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_conflicts FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP TRIGGER IF EXISTS eg_pm_conflict_fact_append_trg ON estimate_generation_project_model_conflict_facts;
CREATE TRIGGER eg_pm_conflict_fact_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_conflict_facts FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP TRIGGER IF EXISTS eg_pm_fact_evidence_append_trg ON estimate_generation_project_model_fact_evidence;
CREATE TRIGGER eg_pm_fact_evidence_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_fact_evidence FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP TRIGGER IF EXISTS eg_pm_derived_append_trg ON estimate_generation_project_model_derived_quantities;
CREATE TRIGGER eg_pm_derived_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_derived_quantities FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP TRIGGER IF EXISTS eg_pm_derived_operand_append_trg ON estimate_generation_project_model_derived_operands;
CREATE TRIGGER eg_pm_derived_operand_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_derived_operands FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Project model v2 validation is forward-only.');
    }
};
