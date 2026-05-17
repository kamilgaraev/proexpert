<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_material_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('organization_warehouses')->nullOnDelete();
            $table->foreignId('warehouse_project_allocation_id')->nullable()->constrained('warehouse_project_allocations')->nullOnDelete();
            $table->foreignId('site_request_id')->nullable()->constrained('site_requests')->nullOnDelete();
            $table->foreignId('purchase_request_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('outbound_movement_id')->nullable()->constrained('warehouse_movements')->nullOnDelete();
            $table->foreignId('inbound_movement_id')->nullable()->constrained('warehouse_movements')->nullOnDelete();
            $table->enum('source_type', ['warehouse', 'purchase', 'manual'])->default('warehouse');
            $table->enum('status', [
                'requested',
                'processing',
                'reserved',
                'preparing',
                'in_transit',
                'partially_delivered',
                'delivered',
                'accepted',
                'problem',
                'cancelled',
            ])->default('reserved');
            $table->decimal('requested_quantity', 15, 3)->default(0);
            $table->decimal('reserved_quantity', 15, 3)->default(0);
            $table->decimal('shipped_quantity', 15, 3)->default(0);
            $table->decimal('accepted_quantity', 15, 3)->default(0);
            $table->date('planned_delivery_date')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('receiver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'status'], 'idx_pmd_org_project_status');
            $table->index(['project_id', 'material_id', 'status'], 'idx_pmd_project_material_status');
            $table->index('site_request_id', 'idx_pmd_site_request');
            $table->index('purchase_request_id', 'idx_pmd_purchase_request');
            $table->index('purchase_order_id', 'idx_pmd_purchase_order');
            $table->index('warehouse_project_allocation_id', 'idx_pmd_allocation');
            $table->index(['warehouse_id', 'material_id', 'status'], 'idx_pmd_warehouse_material_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_material_deliveries');
    }
};
