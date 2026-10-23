<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_learning_examples
            DROP CONSTRAINT estimate_learning_source_claim_shape,
            ADD CONSTRAINT estimate_learning_source_claim_shape CHECK (
                (source_type = 'composer_source_claim'
                    AND norm_code IS NULL AND estimate_norm_id IS NULL AND normative_dataset_version_id IS NULL
                    AND source_claim_key IS NOT NULL AND source_claim_key ~ '^[a-f0-9]{64}$'
                    AND source_entity_type IS NOT DISTINCT FROM 'ai_role_run' AND source_entity_id IS NOT NULL
                    AND decision_status = 'proposed' AND is_positive = false AND accepted_at IS NULL)
                OR (source_type <> 'composer_source_claim' AND norm_code IS NOT NULL AND source_claim_key IS NULL)
                OR (source_type IS NOT DISTINCT FROM 'composer_source_claim'
                    AND norm_code IS NULL AND estimate_norm_id IS NULL AND normative_dataset_version_id IS NULL
                    AND source_claim_key IS NOT NULL AND source_claim_key ~ '^[a-f0-9]{64}$'
                    AND source_entity_type IS NOT DISTINCT FROM 'estimate_session' AND source_entity_id IS NOT NULL
                    AND context_payload->>'origin' IS NOT DISTINCT FROM 'work_parameter_component:v1'
                    AND decision_status = 'proposed' AND is_positive = false AND accepted_at IS NULL)
            )
            SQL);
    }

    public function down(): void
    {
        if (DB::table('estimate_generation_learning_examples')->where('source_type', 'composer_source_claim')
            ->where('source_entity_type', 'estimate_session')->exists()) {
            throw new RuntimeException('work_parameter_component_learning_requires_preserved_history');
        }
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_learning_examples
            DROP CONSTRAINT estimate_learning_source_claim_shape,
            ADD CONSTRAINT estimate_learning_source_claim_shape CHECK (
                (source_type = 'composer_source_claim'
                    AND norm_code IS NULL AND estimate_norm_id IS NULL AND normative_dataset_version_id IS NULL
                    AND source_claim_key IS NOT NULL AND source_claim_key ~ '^[a-f0-9]{64}$'
                    AND source_entity_type IS NOT DISTINCT FROM 'ai_role_run' AND source_entity_id IS NOT NULL
                    AND decision_status = 'proposed' AND is_positive = false AND accepted_at IS NULL)
                OR (source_type <> 'composer_source_claim' AND norm_code IS NOT NULL AND source_claim_key IS NULL)
            )
            SQL);
    }
};
