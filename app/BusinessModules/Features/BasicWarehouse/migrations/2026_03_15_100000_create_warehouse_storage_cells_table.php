<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouse_storage_cells')) {
            return;
        }

        Schema::create('warehouse_storage_cells', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('warehouse_zones')->nullOnDelete();
            $table->string('name');
            $table->string('code', 80);
            $table->string('cell_type', 40)->default('storage');
            $table->string('status', 40)->default('available');
            $table->string('rack_number', 50)->nullable();
            $table->string('shelf_number', 50)->nullable();
            $table->string('bin_number', 50)->nullable();
            $table->decimal('capacity', 15, 3)->nullable();
            $table->decimal('max_weight', 15, 3)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'code'], 'warehouse_storage_cells_warehouse_code_unique');
            $table->index(['organization_id', 'warehouse_id', 'is_active'], 'warehouse_storage_cells_org_wh_active_idx');
            $table->index(['warehouse_id', 'zone_id', 'status'], 'warehouse_storage_cells_wh_zone_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_storage_cells');
    }
};
