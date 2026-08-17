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
        Schema::table('site_requests', function (Blueprint $table): void {
            $table->timestampTz('equipment_start_at')->nullable();
            $table->timestampTz('equipment_end_at')->nullable();
        });

        Schema::table('asset_requests', function (Blueprint $table): void {
            $table->foreignId('site_request_id')->nullable()->unique()->constrained('site_requests')->nullOnDelete();
            $table->string('origin_type', 24)->default('manual');
            $table->text('requirements')->nullable();
        });

        Schema::table('machinery_assignments', function (Blueprint $table): void {
            $table->foreignId('asset_request_id')->nullable()->constrained('asset_requests')->nullOnDelete();
            $table->index(['asset_request_id', 'status'], 'machinery_assignments_request_status_index');
        });

        DB::table('asset_requests')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('asset_request_events')
                    ->whereColumn('asset_request_events.asset_request_id', 'asset_requests.id')
                    ->where('asset_request_events.event_type', 'direct_requested');
            })
            ->update(['origin_type' => 'direct']);
    }

    public function down(): void
    {
        Schema::table('machinery_assignments', function (Blueprint $table): void {
            $table->dropIndex('machinery_assignments_request_status_index');
            $table->dropConstrainedForeignId('asset_request_id');
        });
        Schema::table('asset_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_request_id');
            $table->dropColumn(['origin_type', 'requirements']);
        });
        Schema::table('site_requests', function (Blueprint $table): void {
            $table->dropColumn(['equipment_start_at', 'equipment_end_at']);
        });
    }
};
