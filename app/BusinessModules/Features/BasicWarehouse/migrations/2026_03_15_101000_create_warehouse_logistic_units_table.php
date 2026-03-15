<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouse_logistic_units')) {
            return;
        }

        Schema::create('warehouse_logistic_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('warehouse_zones')->nullOnDelete();
            $table->foreignId('cell_id')->nullable()->constrained('warehouse_storage_cells')->nullOnDelete();
            $table->foreignId('parent_unit_id')->nullable()->constrained('warehouse_logistic_units')->nullOnDelete();
            $table->string('name');
            $table->string('code', 80);
            $table->string('unit_type', 40)->default('pallet');
            $table->string('status', 40)->default('available');
            $table->decimal('capacity', 15, 3)->nullable();
            $table->decimal('current_load', 15, 3)->nullable();
            $table->decimal('gross_weight', 15, 3)->nullable();
            $table->decimal('volume', 15, 3)->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'code'], 'warehouse_logistic_units_warehouse_code_unique');
            $table->index(['organization_id', 'warehouse_id', 'status'], 'warehouse_log_units_org_wh_status_idx');
            $table->index(['warehouse_id', 'zone_id', 'cell_id'], 'warehouse_log_units_wh_zone_cell_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_logistic_units');
    }
};
