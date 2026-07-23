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

        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'immutable_audit_events'::regclass
          AND conname = 'immutable_audit_events_domain_check_v2'
    ) THEN
        ALTER TABLE immutable_audit_events VALIDATE CONSTRAINT immutable_audit_events_domain_check_v2;
        ALTER TABLE immutable_audit_events DROP CONSTRAINT IF EXISTS immutable_audit_events_domain_check;
        ALTER TABLE immutable_audit_events
            RENAME CONSTRAINT immutable_audit_events_domain_check_v2 TO immutable_audit_events_domain_check;
    END IF;
END
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('immutable_audit_legal_domain_rollout_is_forward_only');
    }
};
