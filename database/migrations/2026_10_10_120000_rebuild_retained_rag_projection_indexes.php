<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection();
        $settings = $connection->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        try {
            $connection->selectOne("SELECT set_config('statement_timeout', '180s', false), set_config('lock_timeout', '5s', false)");
            foreach (['ai_rag_expected_coverage_cover_idx', 'ai_rag_expected_identity_unique', 'ai_rag_expected_type_entity_idx',
                'ai_rag_expected_retention_idx', 'ai_rag_expected_sources_pkey', 'ai_rag_expected_scope_idx'] as $name) {
                $index = $connection->selectOne("SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?) AND i.indrelid = to_regclass('public.ai_rag_expected_sources')", ['public.'.$name]);
                if ($index !== null) { $connection->statement('REINDEX INDEX public.'.$name); }
            }
        } finally {
            $connection->selectOne("SELECT set_config('statement_timeout', ?, false), set_config('lock_timeout', ?, false)",
                [$settings->statement_timeout, $settings->lock_timeout]);
        }
    }

    public function down(): void {}
};
