<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->foreignId('machinery_id')->nullable()->constrained('machinery')->nullOnDelete();
            $table->string('name');
            $table->string('inventory_number', 120);
            $table->string('serial_number', 160)->nullable();
            $table->string('qr_code', 160)->nullable();
            $table->string('accounting_mode', 32)->default('serialized');
            $table->string('ownership_type', 32)->default('owned');
            $table->string('lifecycle_status', 32)->default('active');
            $table->string('technical_status', 32)->default('serviceable');
            $table->foreignId('current_warehouse_id')->nullable()->constrained('organization_warehouses')->nullOnDelete();
            $table->foreignId('current_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'inventory_number'], 'organization_assets_org_inventory_unique');
            $table->unique(['organization_id', 'serial_number'], 'organization_assets_org_serial_unique');
            $table->unique(['organization_id', 'qr_code'], 'organization_assets_org_qr_unique');
            $table->index(['organization_id', 'lifecycle_status'], 'organization_assets_org_lifecycle_index');
            $table->index(['organization_id', 'technical_status'], 'organization_assets_org_technical_index');
            $table->index(['organization_id', 'current_project_id'], 'organization_assets_org_project_index');
            $table->index(['organization_id', 'current_warehouse_id'], 'organization_assets_org_warehouse_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_assets');
    }
};
