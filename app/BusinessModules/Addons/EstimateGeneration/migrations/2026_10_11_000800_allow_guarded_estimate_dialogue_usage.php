<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Guarded dialogue usage requires PostgreSQL.');
        }
        DB::statement('ALTER TABLE estimate_generation_ai_usage ALTER COLUMN operation TYPE varchar(40)');
        foreach (['eg_usage_stage_ck', 'eg_usage_operation_ck', 'eg_usage_stage_operation_ck'] as $constraint) {
            DB::statement("ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT {$constraint}");
        }
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_stage_ck CHECK (stage IN ('understand_documents','checking_geometry','plan_work_items','match_normatives','validate_draft','estimate_dialogue'))");
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_operation_ck CHECK (operation IN ('ocr','vision','rerank','completeness_review','project_synthesis','estimate_composition','estimate_audit','estimate_composer_correction','estimate_dialogue_interpretation'))");
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_stage_operation_ck CHECK ((stage = 'understand_documents' AND operation IN ('ocr','vision','project_synthesis')) OR (stage = 'checking_geometry' AND operation = 'vision') OR (stage = 'plan_work_items' AND operation = 'estimate_composition') OR (stage = 'match_normatives' AND operation = 'rerank') OR (stage = 'validate_draft' AND operation IN ('completeness_review','estimate_audit','estimate_composer_correction')) OR (stage = 'estimate_dialogue' AND operation = 'estimate_dialogue_interpretation'))");
    }

    public function down(): void
    {
        throw new RuntimeException('Dialogue expense history is forward-only.');
    }
};
