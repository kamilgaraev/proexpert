<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_role_assignments', static function (Blueprint $table): void {
            $table->foreignId('custom_role_id')->nullable()->index()
                ->constrained('organization_custom_roles')->cascadeOnDelete();
        });

        DB::statement("UPDATE user_role_assignments AS assignments
            SET custom_role_id = roles.id
            FROM authorization_contexts AS contexts
            LEFT JOIN authorization_contexts AS parents ON parents.id = contexts.parent_context_id,
                organization_custom_roles AS roles
            WHERE assignments.context_id = contexts.id AND assignments.role_type = 'custom'
                AND assignments.role_slug = roles.slug
                AND roles.organization_id = CASE
                    WHEN contexts.type = 'organization' THEN contexts.resource_id
                    WHEN contexts.type = 'project' AND parents.type = 'organization' THEN parents.resource_id
                    ELSE NULL END");
    }

    public function down(): void
    {
        Schema::table('user_role_assignments', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('custom_role_id');
        });
    }
};
