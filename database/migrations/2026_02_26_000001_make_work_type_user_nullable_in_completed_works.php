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

        DB::statement('ALTER TABLE completed_works ALTER COLUMN work_type_id DROP NOT NULL');
        DB::statement('ALTER TABLE completed_works ALTER COLUMN user_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE completed_works ALTER COLUMN work_type_id SET NOT NULL');
        DB::statement('ALTER TABLE completed_works ALTER COLUMN user_id SET NOT NULL');
    }
};
