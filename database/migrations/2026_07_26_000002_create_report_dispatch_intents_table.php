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
        Schema::create('report_dispatch_intents', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->text('event_key');
            $table->unsignedBigInteger('organization_id');
            $table->text('aggregate_type');
            $table->char('aggregate_id', 26);
            $table->text('topic');
            $table->text('status')->default('pending');
            $table->smallInteger('attempt_count')->default(0);
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('available_at', 6);
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at', 6)->nullable();
            $table->timestampTz('published_at', 6)->nullable();
            $table->timestampTz('dead_lettered_at', 6)->nullable();
            $table->text('last_error_code')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        foreach ([
            'ALTER TABLE report_dispatch_intents ADD CONSTRAINT report_dispatch_intents_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT',
            'ALTER TABLE report_dispatch_intents ADD CONSTRAINT report_dispatch_intents_event_key_unique UNIQUE (event_key)',
            "ALTER TABLE report_dispatch_intents ADD CONSTRAINT report_dispatch_intents_aggregate_check CHECK (aggregate_type IN ('run','export'))",
            "ALTER TABLE report_dispatch_intents ADD CONSTRAINT report_dispatch_intents_topic_check CHECK ((aggregate_type='run' AND topic='materialize_run') OR (aggregate_type='export' AND topic='generate_export'))",
            "ALTER TABLE report_dispatch_intents ADD CONSTRAINT report_dispatch_intents_status_check CHECK (status IN ('pending','leased','published','dead_letter'))",
            'ALTER TABLE report_dispatch_intents ADD CONSTRAINT report_dispatch_intents_attempt_check CHECK (attempt_count BETWEEN 0 AND 12)',
            "ALTER TABLE report_dispatch_intents ADD CONSTRAINT report_dispatch_intents_lease_shape_check CHECK ((status='leased' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL) OR (status<>'leased' AND lease_token IS NULL AND lease_expires_at IS NULL))",
            "ALTER TABLE report_dispatch_intents ADD CONSTRAINT report_dispatch_intents_terminal_shape_check CHECK ((status='published' AND published_at IS NOT NULL AND dead_lettered_at IS NULL AND last_error_code IS NULL) OR (status='dead_letter' AND published_at IS NULL AND dead_lettered_at IS NOT NULL AND last_error_code IS NOT NULL) OR (status IN ('pending','leased') AND published_at IS NULL AND dead_lettered_at IS NULL))",
            "CREATE INDEX report_dispatch_intents_due_idx ON report_dispatch_intents (available_at,id) WHERE status='pending'",
            "CREATE INDEX report_dispatch_intents_lease_expiry_idx ON report_dispatch_intents (lease_expires_at,id) WHERE status='leased'",
            'CREATE INDEX report_dispatch_intents_aggregate_idx ON report_dispatch_intents (organization_id,aggregate_type,aggregate_id)',
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_dispatch_intents');
    }
};
