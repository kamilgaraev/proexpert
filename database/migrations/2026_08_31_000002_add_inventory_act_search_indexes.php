<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS inventory_acts_act_number_trgm_idx ON inventory_acts USING GIN (lower(act_number) gin_trgm_ops)');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS inventory_acts_notes_trgm_idx ON inventory_acts USING GIN (lower(COALESCE(notes, '')) gin_trgm_ops)");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS inventory_acts_act_number_trgm_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS inventory_acts_notes_trgm_idx');
    }
};
