<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE estimates DROP CONSTRAINT IF EXISTS estimates_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE estimates
                ADD CONSTRAINT estimates_status_check
                CHECK (status IN ('draft', 'in_review', 'approved', 'rejected', 'cancelled', 'superseded', 'archived'))
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE estimates DROP CONSTRAINT IF EXISTS estimates_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE estimates
                ADD CONSTRAINT estimates_status_check
                CHECK (status IN ('draft', 'in_review', 'approved', 'rejected', 'cancelled', 'superseded', 'archived'))
            SQL);
    }
};
