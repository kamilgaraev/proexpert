<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_rag_index_runs', function (Blueprint $table): void {
            $table->string('entity_type', 100)->nullable();
            $table->string('entity_id', 255)->nullable();
            $table->unsignedInteger('expected_sources')->nullable()->after('indexed_chunks');
            $table->unsignedInteger('processed_sources')->default(0)->after('expected_sources');
            $table->timestampTz('heartbeat_at')->nullable()->after('started_at');
            $table->timestampTz('lease_expires_at')->nullable()->after('heartbeat_at');
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('scan_completed_at')->nullable()->after('finished_at');
            $table->index(['status', 'lease_expires_at'], 'ai_rag_index_runs_lease_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_rag_index_runs', function (Blueprint $table): void {
            $table->dropIndex('ai_rag_index_runs_lease_idx');
            $table->dropColumn([
                'entity_type',
                'entity_id',
                'expected_sources',
                'processed_sources',
                'heartbeat_at',
                'lease_expires_at',
                'lease_token',
                'scan_completed_at',
            ]);
        });
    }
};
