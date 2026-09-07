<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE projects DROP CONSTRAINT projects_status_check, ADD CONSTRAINT projects_status_check CHECK (status IN ('draft', 'active', 'completed', 'paused', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE projects DROP CONSTRAINT projects_status_check, ADD CONSTRAINT projects_status_check CHECK (status IN ('active', 'completed', 'paused', 'cancelled'))");
    }
};
