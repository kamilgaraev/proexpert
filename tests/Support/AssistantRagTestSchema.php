<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class AssistantRagTestSchema
{
    public static function create(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('assistant_rag_schema_requires_test_environment');
        }

        if (! Schema::hasTable('projects')) {
            Schema::create('projects', static function (Blueprint $table): void {
                $table->id();
            });
        }

        $searchPath = (string) (DB::selectOne("SELECT current_setting('search_path') AS search_path")->search_path ?? '');
        if ($searchPath === '') {
            throw new RuntimeException('assistant_rag_schema_search_path_missing');
        }

        DB::select('SELECT set_config(?, ?, false)', ['search_path', $searchPath.', public']);
        try {
            foreach ([
                '2026_05_23_000001_create_ai_rag_tables.php',
                '2026_09_29_000003_harden_ai_rag_source_identity.php',
                '2026_05_24_000001_create_ai_rag_index_runs_table.php',
                '2026_09_29_000004_add_ai_rag_run_lifecycle.php',
                '2026_09_29_000013_create_ai_rag_expected_sources_table.php',
            ] as $migration) {
                (require base_path('app/BusinessModules/Features/AIAssistant/migrations/'.$migration))->up();
            }
        } finally {
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $searchPath]);
        }
    }
}
