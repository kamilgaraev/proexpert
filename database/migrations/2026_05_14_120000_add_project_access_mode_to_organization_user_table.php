<?php

declare(strict_types=1);

use App\Enums\UserProjectAccessMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_user')) {
            return;
        }

        if (! Schema::hasColumn('organization_user', 'project_access_mode')) {
            Schema::table('organization_user', function (Blueprint $table): void {
                $table
                    ->string('project_access_mode')
                    ->default(UserProjectAccessMode::ALL_PROJECTS->value)
                    ->after('is_active');
            });
        }

        DB::table('organization_user')
            ->whereNull('project_access_mode')
            ->update([
                'project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('organization_user')) {
            return;
        }

        if (Schema::hasColumn('organization_user', 'project_access_mode')) {
            Schema::table('organization_user', function (Blueprint $table): void {
                $table->dropColumn('project_access_mode');
            });
        }
    }
};
