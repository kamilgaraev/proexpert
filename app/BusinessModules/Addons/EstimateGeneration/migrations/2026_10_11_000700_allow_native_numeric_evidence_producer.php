<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Native numeric provenance requires PostgreSQL.');
        }
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        $before = "'drawing_analyzer','scope_inference'";
        $after = "'drawing_analyzer','native_numeric_parser','scope_inference'";
        if (! is_string($definition) || substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Native evidence producer guard shape is unexpected.');
        }
        DB::unprepared(str_replace($before, $after, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Native numeric evidence history is forward-only.');
    }
};
