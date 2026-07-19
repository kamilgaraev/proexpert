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
    GREATEST(
        (SELECT last_value FROM immutable_audit_sequence),
        COALESCE((SELECT MAX(sequence_id) FROM immutable_audit_events), 1),
        1
    ),
    (SELECT is_called FROM immutable_audit_sequence) OR EXISTS (SELECT 1 FROM immutable_audit_events)
)
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION immutable_audit_allocate_sequence()
RETURNS bigint AS $$
BEGIN
    RETURN nextval('immutable_audit_sequence');
END;
$$ LANGUAGE plpgsql VOLATILE;
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
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE immutable_audit_events DROP CONSTRAINT IF EXISTS immutable_audit_events_domain_check_v2');
        DB::statement('DROP FUNCTION IF EXISTS immutable_audit_allocate_sequence()');
        DB::statement('DROP SEQUENCE IF EXISTS immutable_audit_sequence');
    }
};
