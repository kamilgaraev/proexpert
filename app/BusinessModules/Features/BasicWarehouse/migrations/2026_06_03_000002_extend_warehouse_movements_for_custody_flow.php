<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_material_deliveries', function (Blueprint $table): void {
            if (!Schema::hasColumn('project_material_deliveries', 'project_warehouse_id')) {
                $table->foreignId('project_warehouse_id')->nullable()->constrained('organization_warehouses')->nullOnDelete();
            }
        });

        Schema::table('warehouse_movements', function (Blueprint $table): void {
            if (!Schema::hasColumn('warehouse_movements', 'related_user_id')) {
                $table->foreignId('related_user_id')->nullable()->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('warehouse_movements', 'operation_category')) {
                $table->string('operation_category', 64)->nullable();
            }

            if (!Schema::hasColumn('warehouse_movements', 'project_material_delivery_id')) {
                $table->foreignId('project_material_delivery_id')->nullable()->constrained('project_material_deliveries')->nullOnDelete();
            }

            $table->index(['organization_id', 'project_id', 'related_user_id'], 'idx_movements_project_related_user');
            $table->index(['operation_category', 'movement_type'], 'idx_movements_category_type');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_movements', function (Blueprint $table): void {
            $table->dropIndex('idx_movements_project_related_user');
            $table->dropIndex('idx_movements_category_type');

            if (Schema::hasColumn('warehouse_movements', 'project_material_delivery_id')) {
                $table->dropConstrainedForeignId('project_material_delivery_id');
            }

            if (Schema::hasColumn('warehouse_movements', 'operation_category')) {
                $table->dropColumn('operation_category');
            }

            if (Schema::hasColumn('warehouse_movements', 'related_user_id')) {
                $table->dropConstrainedForeignId('related_user_id');
            }
        });

        Schema::table('project_material_deliveries', function (Blueprint $table): void {
            if (Schema::hasColumn('project_material_deliveries', 'project_warehouse_id')) {
                $table->dropConstrainedForeignId('project_warehouse_id');
            }
        });
    }
};
