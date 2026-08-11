<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_custody_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_asset_id')->constrained('organization_assets')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->foreignId('from_warehouse_id')->nullable()->constrained('organization_warehouses')->restrictOnDelete();
            $table->foreignId('from_project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('organization_warehouses')->restrictOnDelete();
            $table->foreignId('to_project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['organization_asset_id', 'occurred_at', 'id'], 'asset_custody_events_asset_timeline_index');
            $table->index(['organization_id', 'occurred_at'], 'asset_custody_events_org_timeline_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE asset_custody_events
                ADD CONSTRAINT asset_custody_events_one_destination_check
                CHECK (
                    (CASE WHEN to_warehouse_id IS NULL THEN 0 ELSE 1 END) +
                    (CASE WHEN to_project_id IS NULL THEN 0 ELSE 1 END) +
                    (CASE WHEN to_user_id IS NULL THEN 0 ELSE 1 END) = 1
                )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_custody_events');
    }
};
