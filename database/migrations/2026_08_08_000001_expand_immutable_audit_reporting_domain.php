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
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'immutable_audit_events'::regclass
          AND conname = 'immutable_audit_events_domain_check_v3'
    ) THEN
        ALTER TABLE immutable_audit_events
            ADD CONSTRAINT immutable_audit_events_domain_check_v3
            CHECK (domain IN (
                'payments', 'budgeting', 'mdm', 'rbac', 'one_c_exchange', 'warehouse', 'crm',
                'period_close', 'procurement', 'sod', 'contracts', 'legal_archive', 'reporting'
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

        DB::statement('ALTER TABLE immutable_audit_events DROP CONSTRAINT IF EXISTS immutable_audit_events_domain_check_v3');
    }
};
