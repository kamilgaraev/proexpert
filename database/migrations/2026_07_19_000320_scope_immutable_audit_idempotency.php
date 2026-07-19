<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('immutable_audit_events')) {
            return;
        }

        $concurrently = DB::getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';
        DB::statement("CREATE UNIQUE INDEX{$concurrently} IF NOT EXISTS immutable_audit_source_event_aggregate_unique
            ON immutable_audit_events (organization_id, domain, subject_type, subject_id, source, source_event_id)
            WHERE source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX{$concurrently} IF NOT EXISTS immutable_audit_source_event_legacy_unique
            ON immutable_audit_events (organization_id, domain, source, source_event_id)
            WHERE source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)");
        DB::statement("DROP INDEX{$concurrently} IF EXISTS immutable_audit_source_event_unique");
    }

    public function down(): void
    {
        throw new RuntimeException('immutable_audit_idempotency_indexes_are_forward_only');
    }
};
