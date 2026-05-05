<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS idx_supplementary_agreements_advance_changes ON supplementary_agreements USING GIN (advance_changes)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_supplementary_agreements_subcontract_changes ON supplementary_agreements USING GIN (subcontract_changes)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_supplementary_agreements_advance_changes');
        DB::statement('DROP INDEX IF EXISTS idx_supplementary_agreements_subcontract_changes');
    }
};
