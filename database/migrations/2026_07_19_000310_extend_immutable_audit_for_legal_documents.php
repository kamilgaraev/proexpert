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

        DB::statement('LOCK TABLE immutable_audit_events IN SHARE ROW EXCLUSIVE MODE');
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
CREATE OR REPLACE FUNCTION immutable_audit_sequence_sync()
RETURNS trigger AS $$
DECLARE
    current_value bigint;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('immutable_audit_sequence_sync', 0));

    IF NEW.sequence_id IS NULL THEN
        NEW.sequence_id := nextval('immutable_audit_sequence');
        RETURN NEW;
    END IF;

    SELECT last_value INTO current_value FROM immutable_audit_sequence;
    IF NEW.sequence_id > current_value THEN
        PERFORM setval('immutable_audit_sequence', NEW.sequence_id, true);
    ELSE
        NEW.sequence_id := nextval('immutable_audit_sequence');
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS immutable_audit_sequence_sync ON immutable_audit_events;
CREATE TRIGGER immutable_audit_sequence_sync
BEFORE INSERT ON immutable_audit_events
FOR EACH ROW EXECUTE FUNCTION immutable_audit_sequence_sync();

ALTER TABLE immutable_audit_events
    ALTER COLUMN sequence_id SET DEFAULT nextval('immutable_audit_sequence');
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

        DB::statement('LOCK TABLE immutable_audit_events IN SHARE ROW EXCLUSIVE MODE');
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
