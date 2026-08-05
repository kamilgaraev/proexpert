<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'LOCK TABLE contracts, payment_documents, payment_transactions IN SHARE ROW EXCLUSIVE MODE',
        );
        DB::statement(
            'ALTER TABLE holding_payment_event_coverage_checkpoints '
            .'ALTER COLUMN started_at TYPE timestamptz(6) USING started_at::timestamptz(6)',
        );
        DB::statement(
            'ALTER TABLE holding_payment_transaction_event_versions '
            .'ALTER COLUMN recognized_at TYPE timestamptz(6) USING recognized_at::timestamptz(6), '
            .'ALTER COLUMN occurred_at TYPE timestamptz(6) USING occurred_at::timestamptz(6), '
            .'ALTER COLUMN recorded_at TYPE timestamptz(6) USING recorded_at::timestamptz(6)',
        );

        DB::unprepared(<<<'SQL'
DO $$
DECLARE
    checkpoint_at timestamptz(6) := clock_timestamp()::timestamptz(6);
    captured_source_count bigint;
    checkpoint_event_count bigint;
BEGIN
    INSERT INTO holding_payment_transaction_event_versions (
        transaction_id,
        payment_document_id,
        organization_id,
        project_id,
        contract_id,
        document_organization_id,
        document_project_id,
        contract_organization_id,
        contract_project_id,
        amount,
        currency,
        status,
        active,
        recognized_at,
        occurred_at,
        recorded_at,
        history_complete,
        source_hash
    )
    SELECT
        source.transaction_id,
        source.payment_document_id,
        source.organization_id,
        source.project_id,
        source.contract_id,
        source.document_organization_id,
        source.document_project_id,
        source.contract_organization_id,
        source.contract_project_id,
        source.amount,
        source.currency,
        source.status,
        true,
        source.recognized_at,
        checkpoint_at,
        checkpoint_at,
        source.history_complete,
        encode(sha256(convert_to(jsonb_build_object(
            'transaction_id', source.transaction_id,
            'payment_document_id', source.payment_document_id,
            'organization_id', source.organization_id,
            'project_id', source.project_id,
            'contract_id', source.contract_id,
            'document_organization_id', source.document_organization_id,
            'document_project_id', source.document_project_id,
            'contract_organization_id', source.contract_organization_id,
            'contract_project_id', source.contract_project_id,
            'amount', source.amount,
            'currency', source.currency,
            'status', source.status,
            'active', true,
            'recognized_at', source.recognized_at,
            'occurred_at', checkpoint_at,
            'history_complete', source.history_complete
        )::text, 'UTF8')), 'hex')
    FROM (
        SELECT
            transaction_row.id AS transaction_id,
            transaction_row.payment_document_id,
            transaction_row.organization_id,
            transaction_row.project_id,
            document_row.invoiceable_id AS contract_id,
            document_row.organization_id AS document_organization_id,
            document_row.project_id AS document_project_id,
            contract_row.organization_id AS contract_organization_id,
            contract_row.project_id AS contract_project_id,
            transaction_row.amount,
            NULLIF(upper(btrim(transaction_row.currency)), '') AS currency,
            transaction_row.status,
            CASE
                WHEN COALESCE(
                    transaction_row.value_date,
                    transaction_row.transaction_date,
                    transaction_row.created_at::date
                ) IS NULL THEN NULL
                ELSE timezone('UTC', COALESCE(
                    transaction_row.value_date,
                    transaction_row.transaction_date,
                    transaction_row.created_at::date
                )::timestamp)
            END AS recognized_at,
            transaction_row.project_id IS NOT NULL
                AND document_row.invoiceable_id IS NOT NULL
                AND document_row.organization_id IS NOT NULL
                AND document_row.project_id IS NOT NULL
                AND contract_row.organization_id IS NOT NULL
                AND contract_row.project_id IS NOT NULL
                AND transaction_row.organization_id = document_row.organization_id
                AND transaction_row.organization_id = contract_row.organization_id
                AND transaction_row.project_id = document_row.project_id
                AND transaction_row.project_id = contract_row.project_id
                AND transaction_row.amount IS NOT NULL
                AND NULLIF(upper(btrim(transaction_row.currency)), '') IS NOT NULL
                AND char_length(NULLIF(upper(btrim(transaction_row.currency)), '')) = 3
                AND COALESCE(
                    transaction_row.value_date,
                    transaction_row.transaction_date,
                    transaction_row.created_at::date
                ) IS NOT NULL AS history_complete
        FROM payment_transactions AS transaction_row
        INNER JOIN payment_documents AS document_row
            ON document_row.id = transaction_row.payment_document_id
        LEFT JOIN contracts AS contract_row
            ON contract_row.id = document_row.invoiceable_id
        WHERE transaction_row.status IN ('completed', 'refunded')
          AND document_row.invoiceable_type = 'App\Models\Contract'
    ) AS source;

    GET DIAGNOSTICS captured_source_count = ROW_COUNT;

    SELECT COUNT(*)
    INTO checkpoint_event_count
    FROM holding_payment_transaction_event_versions
    WHERE recorded_at = checkpoint_at;

    IF checkpoint_event_count <> captured_source_count THEN
        RAISE EXCEPTION 'holding payment checkpoint precision mismatch';
    END IF;

    INSERT INTO holding_payment_event_coverage_checkpoints (
        started_at,
        source_max_transaction_id,
        source_count,
        captured_count,
        gap_count,
        content_hash
    )
    SELECT
        checkpoint_at,
        COALESCE(MAX(transaction_id), 0),
        COUNT(*),
        COUNT(*) FILTER (WHERE history_complete),
        COUNT(*) FILTER (WHERE NOT history_complete),
        encode(sha256(convert_to(COALESCE(
            string_agg(source_hash, '|' ORDER BY transaction_id, id),
            ''
        ), 'UTF8')), 'hex')
    FROM holding_payment_transaction_event_versions
    WHERE recorded_at = checkpoint_at;
END
$$
SQL);
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Holding payment checkpoint precision repair is irreversible because its evidence is append-only.',
        );
    }
};
