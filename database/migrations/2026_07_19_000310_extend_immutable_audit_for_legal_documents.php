<?php

declare(strict_types=1);

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRolloutService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('immutable_audit_events')) {
            return;
        }

        (new ImmutableAuditRolloutService)->installCompatibilityPhase(
            DB::connection(),
            (int) config('legal_archive.audit_phase_a_max_duration_hours', 24),
            (string) config('legal_archive.audit_writer_token', ''),
        );
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'immutable_audit_events'::regclass
          AND conname = 'immutable_audit_events_domain_check_v2'
    ) THEN
        ALTER TABLE immutable_audit_events
            ADD CONSTRAINT immutable_audit_events_domain_check_v2
            CHECK (domain IN (
                'payments', 'budgeting', 'mdm', 'rbac', 'one_c_exchange', 'warehouse', 'crm',
                'period_close', 'procurement', 'sod', 'contracts', 'legal_archive'
            )) NOT VALID;
    END IF;
END
$$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE immutable_audit_events DROP CONSTRAINT IF EXISTS immutable_audit_events_domain_check_v2');
        DB::statement('DROP TRIGGER IF EXISTS immutable_audit_sequence_sync ON immutable_audit_events');
        DB::statement('DROP TRIGGER IF EXISTS immutable_audit_writer_guard ON immutable_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS immutable_audit_allocate_sequence()');
        DB::statement('DROP FUNCTION IF EXISTS immutable_audit_allocate_compatible_sequence()');
        DB::statement('DROP FUNCTION IF EXISTS immutable_audit_sync_sequence_after_insert()');
        DB::statement('DROP FUNCTION IF EXISTS immutable_audit_writer_guard()');
        DB::statement('DROP TABLE IF EXISTS immutable_audit_rollout');
        DB::statement('DROP SEQUENCE IF EXISTS immutable_audit_sequence');
    }
};
