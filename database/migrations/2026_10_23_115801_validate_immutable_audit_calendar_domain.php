<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE immutable_audit_events VALIDATE CONSTRAINT immutable_audit_events_domain_check_calendar');
        DB::statement('ALTER TABLE immutable_audit_events DROP CONSTRAINT immutable_audit_events_domain_check');
        DB::statement('ALTER TABLE immutable_audit_events RENAME CONSTRAINT immutable_audit_events_domain_check_calendar TO immutable_audit_events_domain_check');
    }

    public function down(): void
    {
        throw new RuntimeException('immutable_audit_calendar_domain_rollout_is_forward_only');
    }
};
