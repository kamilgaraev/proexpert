<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_generation_ai_usage', function (Blueprint $table): void {
            $table->jsonb('request_context')->default('{}')->after('price_snapshot');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE estimate_generation_ai_usage
                ADD CONSTRAINT eg_usage_request_context_ck CHECK (
                    jsonb_typeof(request_context) = 'object'
                    AND octet_length(request_context::text) <= 1024
                    AND (
                        request_context = '{}'::jsonb
                        OR (
                            (request_context - ARRAY['contract_version','role','reason','source_set','entity_key']) = '{}'::jsonb
                            AND request_context ?& ARRAY['contract_version','role','reason','source_set','entity_key']
                            AND request_context->>'contract_version' = 'targeted-sheet-recheck:v1'
                            AND request_context->>'role' IN ('plan','section','facade','explication','specification','unknown')
                            AND request_context->>'reason' IN ('sheet_role_conflict','sheet_role_insufficient_evidence')
                            AND jsonb_typeof(request_context->'source_set') = 'array'
                            AND jsonb_array_length(request_context->'source_set') BETWEEN 1 AND 2
                            AND NOT jsonb_path_exists(request_context, '$.source_set[*] ? (!(@ like_regex "^document:[1-9][0-9]*/sheet:[1-9][0-9]*$"))')
                            AND (
                                (request_context->'entity_key' = 'null'::jsonb
                                    AND jsonb_array_length(request_context->'source_set') = 2)
                                OR (jsonb_typeof(request_context->'entity_key') = 'string'
                                    AND request_context->>'entity_key' ~ '^[a-z0-9][a-z0-9._:-]{0,79}$'
                                    AND jsonb_array_length(request_context->'source_set') = 1)
                            )
                            AND (jsonb_array_length(request_context->'source_set') = 1
                                OR request_context->'source_set'->>0 <> request_context->'source_set'->>1)
                            AND NOT jsonb_path_exists(request_context, '$.** ? (@.type() == "string" && (@ like_regex "(prompt|content|filename|path|secret|token|authorization)" flag "i"))')
                        )
                    )
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_ai_usage DROP CONSTRAINT IF EXISTS eg_usage_request_context_ck');
        }
        Schema::table('estimate_generation_ai_usage', function (Blueprint $table): void {
            $table->dropColumn('request_context');
        });
    }
};
