<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS organizations_name_trgm_idx ON organizations USING GIN (lower(name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS organizations_legal_name_trgm_idx ON organizations USING GIN (lower(legal_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS organizations_city_trgm_idx ON organizations USING GIN (lower(city) gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organizations_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS organizations_legal_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS organizations_city_trgm_idx');
    }
};
