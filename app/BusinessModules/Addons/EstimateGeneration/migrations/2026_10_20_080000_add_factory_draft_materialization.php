<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE estimate_generation_factory_checkpoints DROP CONSTRAINT eg_factory_step_kind_ck');
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_factory_checkpoints ADD CONSTRAINT eg_factory_step_kind_ck CHECK (
                (step_kind = 'inspect_input' AND source_checkpoint_id IS NULL AND result_state_version IS NULL
                    AND result_input_fingerprint IS NULL AND catalog_fingerprint IS NULL)
                OR (step_kind IN ('inspect_plan', 'materialize_draft') AND source_checkpoint_id IS NOT NULL AND source_checkpoint_id <> id
                    AND (result_state_version IS NULL OR result_state_version BETWEEN 0 AND 2147483647)
                    AND (result_input_fingerprint IS NULL OR result_input_fingerprint ~ '^[a-f0-9]{64}$')
                    AND (catalog_fingerprint IS NULL OR catalog_fingerprint ~ '^[a-f0-9]{64}$'))
            )
            SQL);
        DB::statement('ALTER TABLE estimate_generation_factory_checkpoints DROP CONSTRAINT eg_factory_lifecycle_ck');
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_factory_checkpoints ADD CONSTRAINT eg_factory_lifecycle_ck CHECK (
                jsonb_typeof(result) = 'object' AND octet_length(result::text) <= 131072 AND (
                    (status = 'running' AND owner_token IS NOT NULL AND lease_expires_at IS NOT NULL AND finished_at IS NULL
                        AND result = '{}'::jsonb AND result_state_version IS NULL AND result_input_fingerprint IS NULL AND catalog_fingerprint IS NULL)
                    OR (status IN ('awaiting_input','source_review_required','planning_required','unavailable','stale','exhausted','draft_materialized')
                        AND owner_token IS NULL AND lease_expires_at IS NULL AND finished_at IS NOT NULL
                        AND result->>'outcome' = status AND result->>'cost_units' = '0'
                        AND jsonb_typeof(result->'question_refs') = 'array' AND jsonb_array_length(result->'question_refs') <= 50
                        AND jsonb_typeof(result->'limitations') = 'array' AND jsonb_array_length(result->'limitations') <= 64
                        AND result->'estimate_complete' = 'false'::jsonb AND result->'paid_actions_enabled' = 'false'::jsonb
                        AND (
                            (step_kind = 'inspect_input' AND status <> 'draft_materialized' AND result->>'step' = 'inspect_input'
                                AND jsonb_exists_all(result, ARRAY['step','outcome','question_refs','total_questions','truncated','limitations','estimate_complete','paid_actions_enabled','cost_units'])
                                AND result - ARRAY['step','outcome','question_refs','total_questions','truncated','limitations','estimate_complete','paid_actions_enabled','cost_units']::text[] = '{}'::jsonb)
                            OR (step_kind = 'inspect_plan' AND status <> 'draft_materialized' AND result->>'step' = 'inspect_plan'
                                AND result_state_version IS NOT NULL
                                AND result->>'source_checkpoint_id' = source_checkpoint_id::text
                                AND jsonb_typeof(result->'result_state_version') = 'number'
                                AND result->>'result_state_version' = result_state_version::text
                                AND result->>'next_action' IN ('answer_prerequisite','review_source','stop')
                                AND jsonb_typeof(result->'planning_refs') IN ('object','null')
                                AND (status IN ('unavailable','stale','exhausted') OR (result_input_fingerprint IS NOT NULL AND catalog_fingerprint IS NOT NULL))
                                AND jsonb_exists_all(result, ARRAY['step','outcome','source_checkpoint_id','result_state_version','planning_refs','question_refs','total_questions','next_action','limitations','estimate_complete','paid_actions_enabled','cost_units'])
                                AND result - ARRAY['step','outcome','source_checkpoint_id','result_state_version','planning_refs','question_refs','total_questions','next_action','limitations','estimate_complete','paid_actions_enabled','cost_units']::text[] = '{}'::jsonb)

                            OR (step_kind = 'materialize_draft' AND result->>'step' = 'materialize_draft'
                                AND result_state_version IS NOT NULL
                                AND result->>'source_checkpoint_id' = source_checkpoint_id::text
                                AND jsonb_typeof(result->'result_state_version') = 'number'
                                AND result->>'result_state_version' = result_state_version::text
                                AND jsonb_typeof(result->'total_questions') = 'number' AND result->>'total_questions' = '0'
                                AND result->'question_refs' = '[]'::jsonb
                                AND (
                                    (status = 'draft_materialized' AND result_state_version = expected_state_version + 1
                                        AND result_input_fingerprint IS NOT NULL AND catalog_fingerprint IS NOT NULL
                                        AND result->>'next_action' = 'review_draft'
                                        AND jsonb_typeof(result->'draft_refs') = 'object'
                                        AND jsonb_exists_all(result->'draft_refs', ARRAY['revision_id','artifact_hash','rows_count'])
                                        AND (result->'draft_refs') - ARRAY['revision_id','artifact_hash','rows_count']::text[] = '{}'::jsonb
                                        AND jsonb_typeof(result->'draft_refs'->'revision_id') = 'string'
                                        AND jsonb_typeof(result->'draft_refs'->'artifact_hash') = 'string'
                                        AND result->'draft_refs'->>'revision_id' ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                                        AND result->'draft_refs'->>'artifact_hash' ~ '^[a-f0-9]{64}$'
                                        AND jsonb_typeof(result->'draft_refs'->'rows_count') = 'number'
                                        AND result->'draft_refs'->>'rows_count' ~ '^([1-9][0-9]{0,2}|1000)$')
                                    OR (status IN ('unavailable','stale','exhausted') AND result->>'next_action' = 'stop' AND result->'draft_refs' = 'null'::jsonb)
                                )
                                AND jsonb_exists_all(result, ARRAY['step','outcome','source_checkpoint_id','result_state_version','draft_refs','question_refs','total_questions','next_action','limitations','estimate_complete','paid_actions_enabled','cost_units'])
                                AND result - ARRAY['step','outcome','source_checkpoint_id','result_state_version','draft_refs','question_refs','total_questions','next_action','limitations','estimate_complete','paid_actions_enabled','cost_units']::text[] = '{}'::jsonb)
                        ))
                )
            )
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('factory_materialization_history_requires_forward_migration');
    }
};
