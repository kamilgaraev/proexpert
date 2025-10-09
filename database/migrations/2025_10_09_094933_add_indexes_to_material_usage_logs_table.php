<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('material_usage_logs', function (Blueprint $table) {
            $table->index('project_id', 'idx_material_usage_logs_project_id');
            $table->index('material_id', 'idx_material_usage_logs_material_id');
            $table->index('user_id', 'idx_material_usage_logs_user_id');
            $table->index('usage_date', 'idx_material_usage_logs_usage_date');
            $table->index('operation_type', 'idx_material_usage_logs_operation_type');
            $table->index('supplier_id', 'idx_material_usage_logs_supplier_id');
            $table->index('work_type_id', 'idx_material_usage_logs_work_type_id');
            
            $table->index(['project_id', 'usage_date'], 'idx_material_usage_logs_project_date');
            $table->index(['material_id', 'usage_date'], 'idx_material_usage_logs_material_date');
            $table->index(['operation_type', 'usage_date'], 'idx_material_usage_logs_operation_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_usage_logs', function (Blueprint $table) {
            $table->dropIndex('idx_material_usage_logs_operation_date');
            $table->dropIndex('idx_material_usage_logs_material_date');
            $table->dropIndex('idx_material_usage_logs_project_date');
            
            $table->dropIndex('idx_material_usage_logs_work_type_id');
            $table->dropIndex('idx_material_usage_logs_supplier_id');
            $table->dropIndex('idx_material_usage_logs_operation_type');
            $table->dropIndex('idx_material_usage_logs_usage_date');
            $table->dropIndex('idx_material_usage_logs_user_id');
            $table->dropIndex('idx_material_usage_logs_material_id');
            $table->dropIndex('idx_material_usage_logs_project_id');
        });
    }
};
