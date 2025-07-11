<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('completed_works', function (Blueprint $table) {
            $table->index(['project_id', 'organization_id', 'completion_date'], 'completed_works_project_org_date_idx');
        });

        Schema::table('project_organization', function (Blueprint $table) {
            $table->index(['project_id', 'organization_id', 'role'], 'project_org_role_idx');
        });
    }

    public function down(): void
    {
        Schema::table('completed_works', function (Blueprint $table) {
            $table->dropIndex('completed_works_project_org_date_idx');
        });

        Schema::table('project_organization', function (Blueprint $table) {
            $table->dropIndex('project_org_role_idx');
        });
    }
}; 