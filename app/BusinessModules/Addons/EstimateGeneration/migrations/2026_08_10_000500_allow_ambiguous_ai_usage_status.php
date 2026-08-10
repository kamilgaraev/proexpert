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

        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT eg_usage_status_ck');
        DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT eg_usage_status_http_ck');
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_ai_usage
            ADD CONSTRAINT eg_usage_status_ck
            CHECK (status IN ('succeeded','http_failed','connection_failed','malformed_response','ambiguous'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_ai_usage
            ADD CONSTRAINT eg_usage_status_http_ck CHECK (
                (status = 'http_failed' AND http_code IS NOT NULL AND NOT (http_code BETWEEN 200 AND 299))
                OR (status = 'connection_failed' AND http_code IS NULL)
                OR (status = 'succeeded' AND (http_code IS NULL OR http_code BETWEEN 200 AND 299))
                OR (status = 'malformed_response' AND (http_code IS NULL OR http_code BETWEEN 200 AND 299))
                OR (status = 'ambiguous' AND (http_code IS NULL OR http_code BETWEEN 200 AND 299)
                    AND usage_status = 'unavailable')
            )
            SQL);
    }

    public function down(): void {}
};
