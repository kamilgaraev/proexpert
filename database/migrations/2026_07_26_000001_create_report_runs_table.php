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
        Schema::create('report_runs', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('requester_actor_id');
            $table->text('report_code');
            $table->text('status');
            $table->char('definition_hash', 64);
            $table->char('definition_snapshot_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64)->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->char('idempotency_key_hash', 64);
            $table->char('input_fingerprint', 64);
            $table->text('contract_version');
            $table->text('formula_version');
            $table->text('source_schema_version');
            $table->text('renderer_version');
            $table->jsonb('definition_snapshot');
            $table->text('canonical_query_json');
            $table->jsonb('scope_holding_organization_ids');
            $table->jsonb('scope_project_ids');
            $table->jsonb('scope_resource_ids');
            $table->text('scope_timezone');
            $table->jsonb('filters');
            $table->jsonb('comparison');
            $table->timestampTz('as_of', 6);
            $table->text('locale');
            $table->char('saved_view_id', 26)->nullable();
            $table->unsignedBigInteger('saved_view_revision')->nullable();
            $table->char('saved_view_hash', 64)->nullable();
            $table->text('snapshot_classification');
            $table->text('data_classification');
            $table->jsonb('sensitive_column_ids');
            $table->jsonb('audit_column_ids');
            $table->smallInteger('progress')->default(0);
            $table->unsignedBigInteger('row_count')->nullable();
            $table->jsonb('result_metadata')->nullable();
            $table->jsonb('totals')->default(DB::raw("'[]'::jsonb"));
            $table->text('freshness')->nullable();
            $table->jsonb('quality')->nullable();
            $table->jsonb('provenance')->nullable();
            $table->jsonb('row_schema')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->text('snapshot_kind')->nullable();
            $table->text('snapshot_id')->nullable();
            $table->timestampTz('snapshot_generated_at', 6)->nullable();
            $table->timestampTz('snapshot_stale_at', 6)->nullable();
            $table->jsonb('snapshot_watermarks')->nullable();
            $table->text('snapshot_seal_key_id')->nullable();
            $table->text('snapshot_seal_algorithm')->nullable();
            $table->char('snapshot_sealed_payload_hash', 64)->nullable();
            $table->text('snapshot_seal_signature')->nullable();
            $table->timestampTz('snapshot_sealed_at', 6)->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('queued_at', 6);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('ready_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();
            $table->timestampTz('cancel_requested_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestampTz('expired_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->timestampTz('expires_at', 6);
        });

        foreach ([
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_status_check CHECK (status IN ('queued','materializing','ready','failed','cancelled','expired'))",
            'ALTER TABLE report_runs ADD CONSTRAINT report_runs_progress_check CHECK (progress BETWEEN 0 AND 100)',
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_definition_hash_check CHECK (definition_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_definition_snapshot_hash_check CHECK (definition_snapshot_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_query_hash_check CHECK (query_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_source_hash_check CHECK (source_hash IS NULL OR source_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_result_hash_check CHECK (result_hash IS NULL OR result_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_idempotency_hash_check CHECK (idempotency_key_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_input_fingerprint_check CHECK (input_fingerprint ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_saved_view_check CHECK ((saved_view_id IS NULL AND saved_view_revision IS NULL AND saved_view_hash IS NULL) OR (saved_view_id IS NOT NULL AND saved_view_revision IS NOT NULL AND saved_view_hash IS NOT NULL AND saved_view_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$' AND saved_view_revision > 0 AND saved_view_hash ~ '^[a-f0-9]{64}$'))",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_classification_check CHECK (snapshot_classification IN ('operational','official') AND data_classification IN ('standard','sensitive') AND jsonb_typeof(sensitive_column_ids) = 'array' AND jsonb_typeof(audit_column_ids) = 'array')",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_snapshot_seal_check CHECK ((snapshot_seal_key_id IS NULL AND snapshot_seal_algorithm IS NULL AND snapshot_sealed_payload_hash IS NULL AND snapshot_seal_signature IS NULL AND snapshot_sealed_at IS NULL) OR (snapshot_seal_key_id IS NOT NULL AND snapshot_seal_algorithm IS NOT NULL AND snapshot_sealed_payload_hash IS NOT NULL AND snapshot_seal_signature IS NOT NULL AND snapshot_sealed_at IS NOT NULL AND snapshot_seal_key_id ~ '^[a-z][a-z0-9_.:-]{2,127}$' AND snapshot_seal_algorithm = 'ed25519-sha256' AND snapshot_sealed_payload_hash ~ '^[a-f0-9]{64}$' AND snapshot_seal_signature ~ '^[A-Za-z0-9_-]{86}$'))",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_ready_seal_classification_check CHECK (status NOT IN ('ready','expired') OR ((snapshot_classification = 'operational' AND snapshot_seal_key_id IS NULL) OR (snapshot_classification = 'official' AND snapshot_seal_key_id IS NOT NULL AND snapshot_sealed_at >= snapshot_generated_at)))",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_error_code_check CHECK ((status = 'failed' AND error_code IN ('REPORT_NOT_FOUND','REPORT_SCOPE_FORBIDDEN','REPORT_REQUEST_INVALID','REPORT_FILTER_UNSUPPORTED','REPORT_FILTER_VALUE_NOT_FOUND','REPORT_FILTER_RANGE_INVALID','REPORT_SORT_UNSUPPORTED','REPORT_CURSOR_INVALID','REPORT_IDEMPOTENCY_KEY_INVALID','REPORT_IDEMPOTENCY_CONFLICT','REPORT_SNAPSHOT_NOT_READY','REPORT_EXPORT_NOT_READY','REPORT_OFFICIAL_SNAPSHOT_UNSEALED','REPORT_SNAPSHOT_EXPIRED','REPORT_EXPORT_EXPIRED','REPORT_EXPORT_LIMIT_EXCEEDED','REPORT_RATE_LIMITED','REPORT_SOURCE_UNAVAILABLE','REPORT_DEPENDENCY_FAILED','REPORT_INTERNAL_ERROR')) OR (status <> 'failed' AND error_code IS NULL))",
            'ALTER TABLE report_runs ADD CONSTRAINT report_runs_expiry_order_check CHECK (expires_at > created_at)',
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_ready_identity_check CHECK (status <> 'ready' OR (source_hash IS NOT NULL AND result_hash IS NOT NULL AND snapshot_kind IS NOT NULL AND snapshot_id IS NOT NULL AND snapshot_generated_at IS NOT NULL AND snapshot_watermarks IS NOT NULL AND row_count IS NOT NULL AND result_metadata IS NOT NULL AND freshness IS NOT NULL AND quality IS NOT NULL AND provenance IS NOT NULL AND row_schema IS NOT NULL AND capabilities IS NOT NULL AND ready_at IS NOT NULL AND progress = 100 AND error_code IS NULL AND (snapshot_stale_at IS NULL OR snapshot_stale_at >= snapshot_generated_at)))",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_terminal_timestamps_check CHECK ((status = 'failed') = (failed_at IS NOT NULL) AND (status = 'cancelled') = (cancelled_at IS NOT NULL) AND (status = 'expired') = (expired_at IS NOT NULL))",
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_expired_seal_check CHECK (status <> 'expired' OR (source_hash IS NOT NULL AND result_hash IS NOT NULL AND snapshot_kind IS NOT NULL AND snapshot_id IS NOT NULL AND snapshot_generated_at IS NOT NULL AND snapshot_watermarks IS NOT NULL AND row_count IS NOT NULL AND result_metadata IS NOT NULL AND freshness IS NOT NULL AND quality IS NOT NULL AND provenance IS NOT NULL AND row_schema IS NOT NULL AND capabilities IS NOT NULL AND ready_at IS NOT NULL AND progress = 100 AND expired_at >= expires_at))",
            'CREATE UNIQUE INDEX report_runs_org_idempotency_unique ON report_runs (organization_id, idempotency_key_hash)',
            'CREATE INDEX report_runs_org_id_lookup ON report_runs (organization_id, id)',
            "CREATE INDEX report_runs_queued_idx ON report_runs (queued_at, id) WHERE status = 'queued'",
            "CREATE INDEX report_runs_retention_idx ON report_runs (expires_at, id) WHERE status IN ('ready','expired')",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
