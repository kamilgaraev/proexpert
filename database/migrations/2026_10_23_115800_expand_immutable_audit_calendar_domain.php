<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE immutable_audit_events ADD CONSTRAINT immutable_audit_events_domain_check_calendar
CHECK (domain IN (
    'payments', 'budgeting', 'mdm', 'rbac', 'one_c_exchange', 'warehouse', 'crm',
    'period_close', 'procurement', 'sod', 'contracts', 'legal_archive', 'reporting', 'schedule_management'
)) NOT VALID
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE immutable_audit_events DROP CONSTRAINT IF EXISTS immutable_audit_events_domain_check_calendar');
    }
};
