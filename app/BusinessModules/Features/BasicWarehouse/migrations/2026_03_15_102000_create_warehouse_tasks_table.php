<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('warehouse_zones')->nullOnDelete();
            $table->foreignId('cell_id')->nullable()->constrained('warehouse_storage_cells')->nullOnDelete();
            $table->foreignId('logistic_unit_id')->nullable()->constrained('warehouse_logistic_units')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('inventory_act_id')->nullable()->constrained('inventory_acts')->nullOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('warehouse_movements')->nullOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task_number', 50);
            $table->string('title');
            $table->string('task_type', 40);
            $table->string('status', 30)->default('queued');
            $table->string('priority', 20)->default('normal');
            $table->decimal('planned_quantity', 15, 3)->nullable();
            $table->decimal('completed_quantity', 15, 3)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('source_document_type', 60)->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'task_number'], 'unq_warehouse_tasks_org_number');
            $table->index(['organization_id', 'warehouse_id', 'status'], 'idx_warehouse_tasks_org_wh_status');
            $table->index(['organization_id', 'warehouse_id', 'task_type'], 'idx_warehouse_tasks_org_wh_type');
            $table->index(['organization_id', 'assigned_to_id', 'status'], 'idx_warehouse_tasks_org_assignee_status');
            $table->index(['organization_id', 'due_at'], 'idx_warehouse_tasks_org_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_tasks');
    }
};
