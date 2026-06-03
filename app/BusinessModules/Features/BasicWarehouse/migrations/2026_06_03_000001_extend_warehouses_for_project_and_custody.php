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
        Schema::table('organization_warehouses', function (Blueprint $table): void {
            if (!Schema::hasColumn('organization_warehouses', 'project_id')) {
                $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            }

            if (!Schema::hasColumn('organization_warehouses', 'responsible_user_id')) {
                $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organization_warehouses DROP CONSTRAINT IF EXISTS organization_warehouses_warehouse_type_check');
            DB::statement(
                "ALTER TABLE organization_warehouses ADD CONSTRAINT organization_warehouses_warehouse_type_check CHECK (warehouse_type IN ('central', 'project', 'external', 'custody'))"
            );
        }

        Schema::table('organization_warehouses', function (Blueprint $table): void {
            $table->index(['organization_id', 'project_id', 'warehouse_type'], 'idx_org_wh_project_type');
            $table->index(['organization_id', 'responsible_user_id', 'warehouse_type'], 'idx_org_wh_responsible_type');
        });
    }

    public function down(): void
    {
        Schema::table('organization_warehouses', function (Blueprint $table): void {
            $table->dropIndex('idx_org_wh_project_type');
            $table->dropIndex('idx_org_wh_responsible_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organization_warehouses DROP CONSTRAINT IF EXISTS organization_warehouses_warehouse_type_check');
            DB::statement(
                "ALTER TABLE organization_warehouses ADD CONSTRAINT organization_warehouses_warehouse_type_check CHECK (warehouse_type IN ('central', 'project', 'external'))"
            );
        }

        Schema::table('organization_warehouses', function (Blueprint $table): void {
            if (Schema::hasColumn('organization_warehouses', 'responsible_user_id')) {
                $table->dropConstrainedForeignId('responsible_user_id');
            }

            if (Schema::hasColumn('organization_warehouses', 'project_id')) {
                $table->dropConstrainedForeignId('project_id');
            }
        });
    }
};
