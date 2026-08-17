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

        DB::unprepared(<<<'SQL'
            ALTER TABLE estimate_generation_ai_usage
            ADD CONSTRAINT eg_usage_snapshot_rate_types_ck CHECK (
                price_snapshot = '{}'::jsonb OR (
                    (NOT price_snapshot ? 'input_per_million' OR jsonb_typeof(price_snapshot->'input_per_million') = 'string')
                    AND (NOT price_snapshot ? 'cached_input_per_million' OR jsonb_typeof(price_snapshot->'cached_input_per_million') = 'string')
                    AND (NOT price_snapshot ? 'output_per_million' OR jsonb_typeof(price_snapshot->'output_per_million') = 'string')
                    AND (NOT price_snapshot ? 'reasoning_per_million' OR jsonb_typeof(price_snapshot->'reasoning_per_million') = 'string')
                    AND (NOT price_snapshot ? 'image_unit' OR jsonb_typeof(price_snapshot->'image_unit') = 'string')
                    AND (NOT price_snapshot ? 'page_unit' OR jsonb_typeof(price_snapshot->'page_unit') = 'string')
                )
            ) NOT VALID
            SQL);
        DB::statement('ALTER TABLE estimate_generation_ai_usage VALIDATE CONSTRAINT eg_usage_snapshot_rate_types_ck');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT IF EXISTS eg_usage_snapshot_rate_types_ck');
    }
};
