<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['input_payload', 'analysis_payload', 'draft_payload', 'problem_flags'] as $column) {
            DB::statement("ALTER TABLE estimate_generation_sessions ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['input_payload', 'analysis_payload', 'draft_payload', 'problem_flags'] as $column) {
            DB::statement("ALTER TABLE estimate_generation_sessions ALTER COLUMN {$column} TYPE json USING {$column}::json");
        }
    }
};
