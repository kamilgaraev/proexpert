<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_audit_intents', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->text('event_key');
            $table->text('event_type');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('actor_id');
            $table->jsonb('subject');
            $table->text('status')->default('pending');
            $table->smallInteger('attempt_count')->default(0);
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('available_at', 6);
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at', 6)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->timestampTz('dead_lettered_at', 6)->nullable();
            $table->text('last_error_code')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        $events = "'report.run.queued','report.run.materializing','report.run.ready','report.run.failed','report.run.cancelled','report.run.expired','report.export.queued','report.export.running','report.export.uploading','report.export.ready','report.export.failed','report.export.cancelled','report.export.expired','report.export.artifact_deleted'";
        foreach ([
            'ALTER TABLE report_audit_intents ADD CONSTRAINT report_audit_intents_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT',
            'ALTER TABLE report_audit_intents ADD CONSTRAINT report_audit_intents_event_key_unique UNIQUE (event_key)',
            "ALTER TABLE report_audit_intents ADD CONSTRAINT report_audit_intents_event_type_check CHECK (event_type IN ({$events}))",
            "ALTER TABLE report_audit_intents ADD CONSTRAINT report_audit_intents_status_check CHECK (status IN ('pending','leased','delivered','dead_letter'))",
            'ALTER TABLE report_audit_intents ADD CONSTRAINT report_audit_intents_attempt_check CHECK (attempt_count BETWEEN 0 AND 12)',
            "ALTER TABLE report_audit_intents ADD CONSTRAINT report_audit_intents_subject_object_check CHECK (jsonb_typeof(subject)='object')",
            "ALTER TABLE report_audit_intents ADD CONSTRAINT report_audit_intents_lease_shape_check CHECK ((status='leased' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL) OR (status<>'leased' AND lease_token IS NULL AND lease_expires_at IS NULL))",
            "ALTER TABLE report_audit_intents ADD CONSTRAINT report_audit_intents_terminal_shape_check CHECK ((status='delivered' AND delivered_at IS NOT NULL AND dead_lettered_at IS NULL AND last_error_code IS NULL) OR (status='dead_letter' AND delivered_at IS NULL AND dead_lettered_at IS NOT NULL AND last_error_code IS NOT NULL) OR (status IN ('pending','leased') AND delivered_at IS NULL AND dead_lettered_at IS NULL))",
            "CREATE INDEX report_audit_intents_due_idx ON report_audit_intents (available_at,id) WHERE status='pending'",
            "CREATE INDEX report_audit_intents_lease_expiry_idx ON report_audit_intents (lease_expires_at,id) WHERE status='leased'",
            'CREATE INDEX report_audit_intents_organization_event_idx ON report_audit_intents (organization_id,event_type,occurred_at,id)',
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_audit_intents');
    }
};
