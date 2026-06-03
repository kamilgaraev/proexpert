<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_materials', function (Blueprint $table): void {
            if (!Schema::hasColumn('journal_materials', 'warehouse_movement_id')) {
                $table->foreignId('warehouse_movement_id')->nullable()->constrained('warehouse_movements')->nullOnDelete();
            }

            if (!Schema::hasColumn('journal_materials', 'custody_warehouse_id')) {
                $table->foreignId('custody_warehouse_id')->nullable()->constrained('organization_warehouses')->nullOnDelete();
            }

            $table->index(['warehouse_movement_id', 'custody_warehouse_id'], 'idx_journal_materials_consumption_movement');
        });
    }

    public function down(): void
    {
        Schema::table('journal_materials', function (Blueprint $table): void {
            $table->dropIndex('idx_journal_materials_consumption_movement');

            if (Schema::hasColumn('journal_materials', 'custody_warehouse_id')) {
                $table->dropConstrainedForeignId('custody_warehouse_id');
            }

            if (Schema::hasColumn('journal_materials', 'warehouse_movement_id')) {
                $table->dropConstrainedForeignId('warehouse_movement_id');
            }
        });
    }
};
