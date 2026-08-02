<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX report_runs_source_identity_lookup_idx '
            .'ON report_runs (organization_id, report_code, as_of, ready_at DESC) '
            ."WHERE status = 'ready'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS report_runs_source_identity_lookup_idx');
    }
};
