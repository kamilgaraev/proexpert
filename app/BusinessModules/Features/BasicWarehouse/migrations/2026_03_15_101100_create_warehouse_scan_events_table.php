<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouse_scan_events')) {
            return;
        }

        Schema::create('warehouse_scan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('organization_warehouses')->nullOnDelete();
            $table->foreignId('identifier_id')->nullable()->constrained('warehouse_identifiers')->nullOnDelete();
            $table->foreignId('logistic_unit_id')->nullable()->constrained('warehouse_logistic_units')->nullOnDelete();
            $table->unsignedBigInteger('scanned_by_id')->nullable();
            $table->string('code', 190);
            $table->string('source', 40)->default('admin');
            $table->string('result', 40)->default('resolved');
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('scan_context', 120)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['organization_id', 'warehouse_id', 'scanned_at'], 'warehouse_scan_events_org_wh_scanned_idx');
            $table->index(['organization_id', 'result', 'source'], 'warehouse_scan_events_org_result_source_idx');
            $table->index(['entity_type', 'entity_id'], 'warehouse_scan_events_entity_idx');
            $table->index(['code', 'scanned_at'], 'warehouse_scan_events_code_scanned_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_scan_events');
    }
};
