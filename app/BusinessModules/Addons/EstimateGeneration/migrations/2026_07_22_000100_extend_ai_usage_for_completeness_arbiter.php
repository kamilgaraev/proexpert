<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT IF EXISTS eg_usage_stage_ck');
        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT IF EXISTS eg_usage_operation_ck');
        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT IF EXISTS eg_usage_stage_operation_ck');
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_stage_ck CHECK (stage IN ('understand_documents','match_normatives','validate_draft'))");
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_operation_ck CHECK (operation IN ('ocr','vision','rerank','completeness_review'))");
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_stage_operation_ck CHECK ((stage = 'understand_documents' AND operation IN ('ocr','vision')) OR (stage = 'match_normatives' AND operation = 'rerank') OR (stage = 'validate_draft' AND operation = 'completeness_review'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT IF EXISTS eg_usage_stage_ck');
        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT IF EXISTS eg_usage_operation_ck');
        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT IF EXISTS eg_usage_stage_operation_ck');
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_stage_ck CHECK (stage IN ('understand_documents','match_normatives'))");
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_operation_ck CHECK (operation IN ('ocr','vision','rerank'))");
        DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_stage_operation_ck CHECK ((stage = 'match_normatives') = (operation = 'rerank'))");
    }
};
