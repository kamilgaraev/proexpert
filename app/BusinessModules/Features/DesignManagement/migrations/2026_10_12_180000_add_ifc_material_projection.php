<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE design_ifc_model_elements ADD COLUMN source_materials jsonb GENERATED ALWAYS AS (CASE WHEN jsonb_typeof(properties -> 'materials') = 'array' THEN properties -> 'materials' ELSE '[]'::jsonb END) STORED");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE design_ifc_model_elements DROP COLUMN source_materials');
    }
};
