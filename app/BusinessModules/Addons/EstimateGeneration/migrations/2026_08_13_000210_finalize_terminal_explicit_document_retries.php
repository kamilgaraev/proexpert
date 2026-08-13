<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('estimate_generation_documents')
            || ! Schema::hasTable('estimate_generation_processing_units')) {
            return;
        }

        DB::statement(<<<'SQL'
WITH terminal_retries AS (
    SELECT
        d.id,
        max(coalesce(u.failed_at, u.completed_at, u.updated_at)) AS completed_at,
        sum(
            CASE
                WHEN jsonb_typeof(u.metadata->'actual_execution_count') = 'number'
                    THEN greatest((u.metadata->>'actual_execution_count')::integer, 0)
                WHEN u.started_at IS NOT NULL THEN 1
                ELSE 0
            END
        )::integer AS actual_execution_count,
        min(u.failure_fingerprint) AS failure_fingerprint,
        count(DISTINCT u.failure_fingerprint) FILTER (WHERE u.failure_fingerprint IS NOT NULL) AS fingerprint_count
        , count(*)::integer AS included_count
        , count(*) FILTER (WHERE u.status = 'completed')::integer AS ready_count
        , count(*) FILTER (WHERE u.status = 'failed')::integer AS failed_count
    FROM estimate_generation_documents d
    JOIN estimate_generation_processing_units u
      ON u.document_id = d.id
     AND u.source_version = d.source_version
    WHERE d.meta::jsonb->'explicit_document_retry'->>'status' = 'processing'
      AND d.meta::jsonb->'explicit_document_retry'->>'source_version' = d.source_version
      AND nullif(d.meta::jsonb->'explicit_document_retry'->>'attempt_id', '') IS NOT NULL
    GROUP BY d.id
    HAVING count(*) > 0
       AND bool_and(u.status IN ('completed', 'failed', 'excluded'))
       AND bool_and(
           coalesce(u.metadata->>'processing_attempt_id', '')
               = d.meta::jsonb->'explicit_document_retry'->>'attempt_id'
       )
)
UPDATE estimate_generation_documents d
SET meta = jsonb_set(
    coalesce(d.meta::jsonb, '{}'::jsonb),
    '{explicit_document_retry}',
    coalesce(d.meta::jsonb->'explicit_document_retry', '{}'::jsonb)
        || jsonb_strip_nulls(jsonb_build_object(
            'status', CASE
                WHEN d.status = 'failed'
                  OR d.facts_summary::jsonb->'processing_outcome'->>'type' IN ('system_failure', 'temporary_failure')
                    THEN 'failed'
                ELSE 'completed'
            END,
            'completed_at', to_jsonb(coalesce(t.completed_at, d.updated_at, now())),
            'counts', d.facts_summary::jsonb->'processing_outcome'->'counts',
            'actual_execution_count', t.actual_execution_count,
            'terminal_reason', CASE d.facts_summary::jsonb->'processing_outcome'->>'type'
                WHEN 'system_failure' THEN 'system_failure'
                WHEN 'temporary_failure' THEN 'temporary_failure'
                ELSE 'completed'
            END,
            'diagnostic_fingerprint', CASE
                WHEN t.fingerprint_count = 1 AND t.failure_fingerprint ~ '^[0-9a-f]{64}$'
                    THEN 'sha256:' || t.failure_fingerprint
                ELSE NULL
            END
        )),
    true
),
status = CASE WHEN t.failed_count = t.included_count THEN 'failed' ELSE d.status END,
processing_stage = CASE WHEN t.failed_count = t.included_count THEN 'completed' ELSE d.processing_stage END,
progress_percent = CASE WHEN t.failed_count = t.included_count THEN 100 ELSE d.progress_percent END,
processed_page_count = CASE WHEN t.failed_count = t.included_count THEN 0 ELSE d.processed_page_count END,
error_code = CASE WHEN t.failed_count = t.included_count THEN 'document_processing_system_failed' ELSE d.error_code END,
error_message_key = CASE WHEN t.failed_count = t.included_count THEN 'estimate_generation.document_processing_system_failed' ELSE d.error_message_key END,
facts_summary = CASE
    WHEN t.failed_count = t.included_count THEN jsonb_set(
        coalesce(d.facts_summary::jsonb, '{}'::jsonb),
        '{processing_outcome}',
        jsonb_build_object(
            'type', 'system_failure',
            'counts', jsonb_build_object(
                'included', t.included_count,
                'ready', t.ready_count,
                'needs_user_action', 0,
                'system_failed', t.failed_count,
                'processing', 0,
                'excluded', 0
            ),
            'retry_allowed', false
        ),
        true
    )
    ELSE d.facts_summary::jsonb
END,
updated_at = now()
FROM terminal_retries t
WHERE d.id = t.id
SQL);

        DB::statement(<<<'SQL'
UPDATE estimate_generation_sessions s
SET status = 'failed',
    processing_stage = 'failed',
    processing_progress = 100,
    failure_code = 'document_processing_system_failed',
    resume_status = 'processing_documents',
    updated_at = now()
WHERE EXISTS (
    SELECT 1
    FROM estimate_generation_documents d
    WHERE d.session_id = s.id
      AND d.meta::jsonb->'explicit_document_retry'->>'status' = 'failed'
      AND d.error_code = 'document_processing_system_failed'
)
AND NOT EXISTS (
    SELECT 1
    FROM estimate_generation_documents d
    WHERE d.session_id = s.id
      AND d.status IN ('uploaded', 'queued', 'processing')
)
SQL);
    }

    public function down(): void {}
};
