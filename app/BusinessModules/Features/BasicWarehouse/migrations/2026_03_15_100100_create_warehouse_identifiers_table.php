<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouse_identifiers')) {
            return;
        }

        Schema::create('warehouse_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('organization_warehouses')->nullOnDelete();
            $table->string('identifier_type', 40);
            $table->string('code', 190);
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->string('label')->nullable();
            $table->string('status', 40)->default('active');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'warehouse_identifiers_org_code_unique');
            $table->index(['organization_id', 'warehouse_id', 'status'], 'warehouse_identifiers_org_wh_status_idx');
            $table->index(['entity_type', 'entity_id'], 'warehouse_identifiers_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_identifiers');
    }
};
