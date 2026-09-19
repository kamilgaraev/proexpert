<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX project_single_active_general_contractor ON project_organization (project_id) WHERE is_active = true AND COALESCE(NULLIF(role_new, ''), role) = 'general_contractor'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX project_single_active_general_contractor');
    }
};
