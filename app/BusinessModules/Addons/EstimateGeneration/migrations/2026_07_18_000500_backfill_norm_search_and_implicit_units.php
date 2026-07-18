<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
UPDATE estimate_norms
SET search_vector = to_tsvector('russian', coalesce(code,'') || ' ' || coalesce(name,'') || ' ' || coalesce(section_name,''))
WHERE search_vector IS NULL
SQL);
        DB::statement(<<<'SQL'
UPDATE estimate_norm_resources
SET unit = CASE
    WHEN resource_type IN ('labor','machine_labor') THEN 'чел.-ч'
    WHEN resource_type IN ('machine','machinery') THEN 'маш.-ч'
    ELSE unit
END
WHERE NULLIF(BTRIM(unit), '') IS NULL
  AND resource_type IN ('labor','machine_labor','machine','machinery')
SQL);
    }

    public function down(): void {}
};
