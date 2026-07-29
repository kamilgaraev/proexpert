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
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('run_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('requester_actor_id');
            $table->text('correlation_lineage_id')->nullable();
            $table->text('report_code');
            $table->text('status');
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->char('result_hash', 64);
            $table->char('export_hash', 64);
            $table->char('idempotency_key_hash', 64);
            $table->char('input_fingerprint', 64);
            $table->jsonb('scope_holding_organization_ids');
            $table->jsonb('scope_project_ids');
            $table->jsonb('scope_resources');
            $table->text('scope_timezone');
            $table->text('snapshot_kind');
            $table->text('snapshot_id');
            $table->timestampTz('snapshot_generated_at', 6);
            $table->timestampTz('snapshot_stale_at', 6)->nullable();
            $table->jsonb('snapshot_watermarks');
            $table->text('snapshot_classification');
            $table->text('snapshot_seal_key_id')->nullable();
            $table->text('snapshot_seal_algorithm')->nullable();
            $table->char('snapshot_sealed_payload_hash', 64)->nullable();
            $table->text('snapshot_seal_signature')->nullable();
            $table->timestampTz('snapshot_sealed_at', 6)->nullable();
            $table->text('data_classification');
            $table->jsonb('sensitive_column_ids');
            $table->jsonb('audit_column_ids');
            $table->boolean('totals_sensitive');
            $table->boolean('totals_audit');
            $table->boolean('provenance_audit');
            $table->text('contract_version');
            $table->text('formula_version');
            $table->text('source_schema_version');
            $table->text('renderer_version');
            $table->text('format');
            $table->jsonb('selected_columns');
            $table->text('sort_field');
            $table->text('sort_direction');
            $table->text('locale');
            $table->text('render_timezone');
            $table->text('artifact_path')->nullable();
            $table->text('artifact_version_id')->nullable();
            $table->text('artifact_etag')->nullable();
            $table->text('artifact_mime')->nullable();
            $table->char('artifact_checksum', 64)->nullable();
            $table->unsignedBigInteger('artifact_size_bytes')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->uuid('execution_lease_token')->nullable();
            $table->timestampTz('execution_lease_expires_at', 6)->nullable();
            $table->timestampTz('execution_heartbeat_at', 6)->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('queued_at', 6);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('uploading_at', 6)->nullable();
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
            'ALTER TABLE report_exports ADD CONSTRAINT report_exports_run_fk FOREIGN KEY (run_id) REFERENCES report_runs(id) ON DELETE RESTRICT',
            'ALTER TABLE report_exports ADD CONSTRAINT report_exports_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT',
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('queued','running','uploading','ready','failed','cancelled','expired'))",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_ulid_check CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$' AND run_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_hashes_check CHECK (definition_hash ~ '^[a-f0-9]{64}$' AND query_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$' AND result_hash ~ '^[a-f0-9]{64}$' AND export_hash ~ '^[a-f0-9]{64}$' AND idempotency_key_hash ~ '^[a-f0-9]{64}$' AND input_fingerprint ~ '^[a-f0-9]{64}$' AND (artifact_checksum IS NULL OR artifact_checksum ~ '^[a-f0-9]{64}$'))",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_scope_shape_check CHECK (jsonb_typeof(scope_holding_organization_ids) = 'array' AND jsonb_typeof(scope_project_ids) = 'array' AND jsonb_typeof(scope_resources) = 'array')",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_classification_check CHECK (snapshot_classification IN ('operational','official') AND data_classification IN ('standard','sensitive') AND jsonb_typeof(sensitive_column_ids) = 'array' AND jsonb_typeof(audit_column_ids) = 'array')",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_snapshot_seal_check CHECK ((snapshot_seal_key_id IS NULL AND snapshot_seal_algorithm IS NULL AND snapshot_sealed_payload_hash IS NULL AND snapshot_seal_signature IS NULL AND snapshot_sealed_at IS NULL AND snapshot_classification = 'operational') OR (snapshot_seal_key_id IS NOT NULL AND snapshot_seal_algorithm = 'ed25519-sha256' AND snapshot_sealed_payload_hash ~ '^[a-f0-9]{64}$' AND snapshot_seal_signature ~ '^[A-Za-z0-9_-]{86}$' AND snapshot_sealed_at IS NOT NULL AND snapshot_classification = 'official' AND snapshot_sealed_at >= snapshot_generated_at))",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_render_input_check CHECK (format IN ('csv','xlsx','pdf') AND jsonb_typeof(selected_columns) = 'array' AND sort_direction IN ('asc','desc'))",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_correlation_lineage_check CHECK (correlation_lineage_id IS NULL OR correlation_lineage_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_execution_lease_check CHECK ((status IN ('running','uploading') AND execution_lease_token IS NOT NULL AND execution_lease_expires_at IS NOT NULL AND execution_heartbeat_at IS NOT NULL AND execution_lease_expires_at > execution_heartbeat_at) OR (status NOT IN ('running','uploading') AND execution_lease_token IS NULL AND execution_lease_expires_at IS NULL AND execution_heartbeat_at IS NULL))",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_artifact_check CHECK ((status IN ('ready','expired') AND artifact_path IS NOT NULL AND artifact_version_id IS NOT NULL AND length(artifact_version_id) BETWEEN 1 AND 255 AND artifact_etag IS NOT NULL AND length(artifact_etag) BETWEEN 1 AND 255 AND artifact_etag !~ '[[:cntrl:]]' AND artifact_mime IS NOT NULL AND length(artifact_mime) BETWEEN 1 AND 255 AND artifact_checksum IS NOT NULL AND artifact_size_bytes > 0 AND row_count IS NOT NULL AND ready_at IS NOT NULL) OR (status NOT IN ('ready','expired') AND artifact_path IS NULL AND artifact_version_id IS NULL AND artifact_etag IS NULL AND artifact_mime IS NULL AND artifact_checksum IS NULL AND artifact_size_bytes IS NULL AND row_count IS NULL AND ready_at IS NULL))",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_error_code_check CHECK ((status = 'failed' AND error_code IN ('REPORT_NOT_FOUND','REPORT_SCOPE_FORBIDDEN','REPORT_REQUEST_INVALID','REPORT_FILTER_UNSUPPORTED','REPORT_FILTER_VALUE_NOT_FOUND','REPORT_FILTER_RANGE_INVALID','REPORT_SORT_UNSUPPORTED','REPORT_CURSOR_INVALID','REPORT_IDEMPOTENCY_KEY_INVALID','REPORT_IDEMPOTENCY_CONFLICT','REPORT_SNAPSHOT_NOT_READY','REPORT_EXPORT_NOT_READY','REPORT_OFFICIAL_SNAPSHOT_UNSEALED','REPORT_SNAPSHOT_EXPIRED','REPORT_EXPORT_EXPIRED','REPORT_EXPORT_LIMIT_EXCEEDED','REPORT_RATE_LIMITED','REPORT_SOURCE_UNAVAILABLE','REPORT_DEPENDENCY_FAILED','REPORT_INTERNAL_ERROR')) OR (status <> 'failed' AND error_code IS NULL))",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_terminal_timestamps_check CHECK ((status = 'failed') = (failed_at IS NOT NULL) AND (status = 'cancelled') = (cancelled_at IS NOT NULL) AND (status = 'expired') = (expired_at IS NOT NULL) AND (status IN ('cancelled','expired')) = (cancel_requested_at IS NOT NULL OR status = 'expired'))",
            "ALTER TABLE report_exports ADD CONSTRAINT report_exports_expiry_order_check CHECK (expires_at > created_at AND (expired_at IS NULL OR expired_at >= expires_at))",
            'CREATE UNIQUE INDEX report_exports_org_idempotency_unique ON report_exports (organization_id, idempotency_key_hash)',
            'CREATE UNIQUE INDEX report_exports_execution_lease_token_unique ON report_exports (execution_lease_token) WHERE execution_lease_token IS NOT NULL',
            'CREATE INDEX report_exports_org_id_lookup ON report_exports (organization_id, id)',
            'CREATE INDEX report_exports_run_status_idx ON report_exports (run_id, status)',
            "CREATE INDEX report_exports_queued_idx ON report_exports (queued_at, id) WHERE status = 'queued'",
            "CREATE INDEX report_exports_retention_idx ON report_exports (expires_at, id) WHERE status IN ('ready','expired')",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
