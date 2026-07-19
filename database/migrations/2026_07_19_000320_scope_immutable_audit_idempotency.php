<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS immutable_audit_source_event_unique_v2
ON immutable_audit_events (
    organization_id,
    domain,
    subject_type,
    subject_id,
    source,
    source_event_id
)
WHERE source_event_id IS NOT NULL
  AND subject_type IS NOT NULL
  AND subject_id IS NOT NULL
SQL);
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS immutable_audit_source_event_unique');
            DB::statement('ALTER INDEX immutable_audit_source_event_unique_v2 RENAME TO immutable_audit_source_event_unique');

            return;
        }

        Schema::table('immutable_audit_events', function (Blueprint $table): void {
            $table->dropUnique('immutable_audit_source_event_unique');
            $table->unique([
                'organization_id',
                'domain',
                'subject_type',
                'subject_id',
                'source',
                'source_event_id',
            ], 'immutable_audit_source_event_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('immutable_audit_events')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS immutable_audit_source_event_unique_v1
ON immutable_audit_events (organization_id, domain, source, source_event_id)
WHERE source_event_id IS NOT NULL
SQL);
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS immutable_audit_source_event_unique');
            DB::statement('ALTER INDEX immutable_audit_source_event_unique_v1 RENAME TO immutable_audit_source_event_unique');

            return;
        }

        Schema::table('immutable_audit_events', function (Blueprint $table): void {
            $table->dropUnique('immutable_audit_source_event_unique');
            $table->unique(
                ['organization_id', 'domain', 'source', 'source_event_id'],
                'immutable_audit_source_event_unique',
            );
        });
    }
};
