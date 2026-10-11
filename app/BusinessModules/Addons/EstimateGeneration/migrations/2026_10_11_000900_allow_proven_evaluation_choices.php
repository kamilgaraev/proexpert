<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Evaluation decision provenance requires PostgreSQL.');
        }
        DB::statement('ALTER TABLE estimate_generation_project_model_assertions DROP CONSTRAINT eg_project_model_assertions_type_ck');
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_project_model_assertions ADD CONSTRAINT eg_project_model_assertions_type_ck
CHECK (assertion_type ~ '^[a-z][a-z0-9_]{0,63}$' OR assertion_type ~ '^evaluation_requirement\.[a-z][a-z0-9_]{0,40}$')
SQL);
        DB::statement('ALTER TABLE estimate_generation_project_model_corrections ADD COLUMN target_fact_stable_key varchar(191) NULL');
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_project_model_corrections ADD CONSTRAINT eg_evaluation_decision_target_ck
CHECK (target_fact_stable_key IS NULL OR (target_conflict_key IS NULL AND target_fact_stable_key ~ '^[a-z][a-z0-9._:-]{1,191}$'))
SQL);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_evaluation_decision_target_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
BEGIN
  IF NEW.target_fact_stable_key IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM estimate_generation_project_model_assertions fact WHERE fact.organization_id=NEW.organization_id
      AND fact.project_id=NEW.project_id AND fact.session_id=NEW.session_id AND fact.source_version=NEW.source_version
      AND fact.stable_key=NEW.target_fact_stable_key
  ) THEN RAISE EXCEPTION 'estimate_generation.decision_target_outside_scope'; END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER eg_evaluation_decision_target_trg BEFORE INSERT ON estimate_generation_project_model_corrections
FOR EACH ROW EXECUTE FUNCTION eg_evaluation_decision_target_guard();
SQL);
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        $attribute = "'element_type_code','elevation'";
        $stringGuard = "IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) = 'string' AND NOT (";
        if (! is_string($definition) || substr_count($definition, $attribute) !== 1 || substr_count($definition, $stringGuard) !== 1) {
            throw new RuntimeException('Evaluation evidence guard shape is unexpected.');
        }
        $guard = <<<'SQL'
IF NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) = 'evaluation_choice' THEN
    IF NEW.type <> 'source_fact' OR NEW.source_type <> 'user_input' OR NEW.producer_name <> 'user_input_normalizer'
      OR jsonb_typeof(NEW.value->'fact_value') <> 'string' OR NEW.value->>'fact_value' !~ '^[a-f0-9]{64}$'
      OR NEW.value ? 'unit' THEN RAISE EXCEPTION 'estimate_generation.evaluation_choice_evidence_invalid'; END IF;
END IF;
IF COALESCE(NEW.value->>'fact_key','') <> 'evaluation_choice' AND jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) = 'string' AND NOT (
SQL;
        DB::unprepared(str_replace([$attribute, $stringGuard], ["'element_type_code','elevation','evaluation_choice'", $guard], $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Evaluation decision history is forward-only.');
    }
};
