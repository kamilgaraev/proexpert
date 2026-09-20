<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE work_volume_accepted_allocations
ADD COLUMN source_quantity numeric(24,6) GENERATED ALWAYS AS
(quantity * COALESCE((line_snapshot->'conversion_basis'->>'coefficient')::numeric, 1)) STORED
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_volume_accepted_allocations DROP COLUMN source_quantity');
    }
};
