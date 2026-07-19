<?php

declare(strict_types=1);

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

        DB::statement('CREATE SEQUENCE IF NOT EXISTS immutable_audit_sequence');
        DB::statement(<<<'SQL'
SELECT setval(
    'immutable_audit_sequence',
    GREATEST(COALESCE((SELECT MAX(sequence_id) FROM immutable_audit_events), 1), 1),
    EXISTS (SELECT 1 FROM immutable_audit_events)
)
SQL);
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
        DB::statement('ALTER TABLE immutable_audit_events VALIDATE CONSTRAINT immutable_audit_events_domain_check_v2');
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'immutable_audit_events'::regclass
          AND conname = 'immutable_audit_events_domain_check'
    ) THEN
        ALTER TABLE immutable_audit_events DROP CONSTRAINT immutable_audit_events_domain_check;
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'immutable_audit_events'::regclass
          AND conname = 'immutable_audit_events_domain_check_v2'
    ) THEN
        ALTER TABLE immutable_audit_events
            RENAME CONSTRAINT immutable_audit_events_domain_check_v2 TO immutable_audit_events_domain_check;
    END IF;
END
$$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('immutable_audit_events')) {
            return;
        }

        DB::statement('ALTER TABLE immutable_audit_events DROP CONSTRAINT IF EXISTS immutable_audit_events_domain_check');
        DB::statement(<<<'SQL'
ALTER TABLE immutable_audit_events
ADD CONSTRAINT immutable_audit_events_domain_check
CHECK (domain IN (
    'payments', 'budgeting', 'mdm', 'rbac', 'one_c_exchange', 'warehouse', 'crm',
    'period_close', 'procurement', 'sod', 'contracts', 'legal_archive'
)) NOT VALID
SQL);
        DB::statement('ALTER TABLE immutable_audit_events VALIDATE CONSTRAINT immutable_audit_events_domain_check');
    }
};
