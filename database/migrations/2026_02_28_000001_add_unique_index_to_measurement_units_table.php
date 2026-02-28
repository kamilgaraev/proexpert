<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Добавляем уникальный индекс (organization_id, LOWER(short_name))
        // для предотвращения создания дублей единиц измерения ("м2", "м²", "М2").
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS measurement_units_org_short_name_unique ON measurement_units (organization_id, LOWER(short_name)) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS measurement_units_org_short_name_unique');
    }
};
