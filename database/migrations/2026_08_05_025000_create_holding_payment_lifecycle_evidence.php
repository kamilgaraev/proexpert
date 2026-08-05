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
        Schema::create('holding_payment_event_coverage_checkpoints', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->dateTimeTz('started_at');
            $table->unsignedBigInteger('source_max_transaction_id')->default(0);
            $table->unsignedBigInteger('source_count')->default(0);
            $table->unsignedBigInteger('captured_count')->default(0);
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->char('content_hash', 64);
            $table->index('started_at', 'holding_payment_coverage_started');
        });

        Schema::create('holding_payment_transaction_event_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('payment_document_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('document_organization_id')->nullable();
            $table->unsignedBigInteger('document_project_id')->nullable();
            $table->unsignedBigInteger('contract_organization_id')->nullable();
            $table->unsignedBigInteger('contract_project_id')->nullable();
            $table->decimal('amount', 20, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status', 32);
            $table->boolean('active');
            $table->dateTimeTz('recognized_at')->nullable();
            $table->dateTimeTz('occurred_at');
            $table->dateTimeTz('recorded_at');
            $table->boolean('history_complete');
            $table->char('source_hash', 64);
            $table->index(
                ['organization_id', 'project_id', 'recognized_at', 'transaction_id', 'id'],
                'holding_payment_event_scope_lookup',
            );
            $table->index(
                ['transaction_id', 'occurred_at', 'recorded_at', 'id'],
                'holding_payment_event_latest_lookup',
            );
        });

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION holding_payment_event_evidence_append_only()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'holding payment event evidence is append-only';
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement(
            'CREATE TRIGGER holding_payment_event_versions_append_only '
            .'BEFORE UPDATE OR DELETE ON holding_payment_transaction_event_versions '
            .'FOR EACH ROW EXECUTE FUNCTION holding_payment_event_evidence_append_only()',
        );
        DB::statement(
            'CREATE TRIGGER holding_payment_coverage_append_only '
            .'BEFORE UPDATE OR DELETE ON holding_payment_event_coverage_checkpoints '
            .'FOR EACH ROW EXECUTE FUNCTION holding_payment_event_evidence_append_only()',
        );

        DB::statement(
            'LOCK TABLE contracts, payment_documents, payment_transactions IN SHARE ROW EXCLUSIVE MODE',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_append_holding_payment_transaction_event_version_v1(
    transaction_row payment_transactions,
    contract_id_value bigint,
    document_organization_id_value bigint,
    document_project_id_value bigint,
    contract_organization_id_value bigint,
    contract_project_id_value bigint,
    active_value boolean,
    occurred_value timestamptz,
    recorded_value timestamptz
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    recognized_value timestamptz;
    currency_value text;
    history_complete_value boolean;
    payload jsonb;
BEGIN
    recognized_value := CASE
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
    END;
    currency_value := NULLIF(upper(btrim(transaction_row.currency)), '');
    history_complete_value := transaction_row.project_id IS NOT NULL
        AND contract_id_value IS NOT NULL
        AND document_organization_id_value IS NOT NULL
        AND document_project_id_value IS NOT NULL
        AND contract_organization_id_value IS NOT NULL
        AND contract_project_id_value IS NOT NULL
        AND transaction_row.organization_id = document_organization_id_value
        AND transaction_row.organization_id = contract_organization_id_value
        AND transaction_row.project_id = document_project_id_value
        AND transaction_row.project_id = contract_project_id_value
        AND transaction_row.amount IS NOT NULL
        AND currency_value IS NOT NULL
        AND char_length(currency_value) = 3
        AND recognized_value IS NOT NULL;
    payload := jsonb_build_object(
        'transaction_id', transaction_row.id,
        'payment_document_id', transaction_row.payment_document_id,
        'organization_id', transaction_row.organization_id,
        'project_id', transaction_row.project_id,
        'contract_id', contract_id_value,
        'document_organization_id', document_organization_id_value,
        'document_project_id', document_project_id_value,
        'contract_organization_id', contract_organization_id_value,
        'contract_project_id', contract_project_id_value,
        'amount', transaction_row.amount,
        'currency', currency_value,
        'status', transaction_row.status,
        'active', active_value,
        'recognized_at', recognized_value,
        'occurred_at', occurred_value,
        'history_complete', history_complete_value
    );

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
    ) VALUES (
        transaction_row.id,
        transaction_row.payment_document_id,
        transaction_row.organization_id,
        transaction_row.project_id,
        contract_id_value,
        document_organization_id_value,
        document_project_id_value,
        contract_organization_id_value,
        contract_project_id_value,
        transaction_row.amount,
        currency_value,
        transaction_row.status,
        active_value,
        recognized_value,
        occurred_value,
        recorded_value,
        history_complete_value,
        encode(sha256(convert_to(payload::text, 'UTF8')), 'hex')
    );
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_payment_transaction_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_row record;
    occurred_value timestamptz;
    recorded_value timestamptz := clock_timestamp();
BEGIN
    IF TG_OP = 'UPDATE'
       AND NEW.payment_document_id IS NOT DISTINCT FROM OLD.payment_document_id
       AND NEW.organization_id IS NOT DISTINCT FROM OLD.organization_id
       AND NEW.project_id IS NOT DISTINCT FROM OLD.project_id
       AND NEW.amount IS NOT DISTINCT FROM OLD.amount
       AND NEW.currency IS NOT DISTINCT FROM OLD.currency
       AND NEW.transaction_date IS NOT DISTINCT FROM OLD.transaction_date
       AND NEW.value_date IS NOT DISTINCT FROM OLD.value_date
       AND NEW.status IS NOT DISTINCT FROM OLD.status THEN
        RETURN NEW;
    END IF;

    occurred_value := recorded_value;

    IF TG_OP IN ('UPDATE', 'DELETE') AND OLD.status IN ('completed', 'refunded') THEN
        SELECT
            document_row.invoiceable_type,
            document_row.invoiceable_id,
            document_row.organization_id AS document_organization_id,
            document_row.project_id AS document_project_id,
            contract_row.organization_id AS contract_organization_id,
            contract_row.project_id AS contract_project_id
        INTO source_row
        FROM payment_documents AS document_row
        LEFT JOIN contracts AS contract_row
            ON contract_row.id = document_row.invoiceable_id
           AND document_row.invoiceable_type = 'App\Models\Contract'
        WHERE document_row.id = OLD.payment_document_id;

        IF FOUND THEN
            IF source_row.invoiceable_type = 'App\Models\Contract' THEN
                PERFORM most_append_holding_payment_transaction_event_version_v1(
                    OLD,
                    source_row.invoiceable_id,
                    source_row.document_organization_id,
                    source_row.document_project_id,
                    source_row.contract_organization_id,
                    source_row.contract_project_id,
                    false,
                    occurred_value,
                    recorded_value
                );
            END IF;
        END IF;
    END IF;

    IF TG_OP IN ('INSERT', 'UPDATE') AND NEW.status IN ('completed', 'refunded') THEN
        SELECT
            document_row.invoiceable_type,
            document_row.invoiceable_id,
            document_row.organization_id AS document_organization_id,
            document_row.project_id AS document_project_id,
            contract_row.organization_id AS contract_organization_id,
            contract_row.project_id AS contract_project_id
        INTO source_row
        FROM payment_documents AS document_row
        LEFT JOIN contracts AS contract_row
            ON contract_row.id = document_row.invoiceable_id
           AND document_row.invoiceable_type = 'App\Models\Contract'
        WHERE document_row.id = NEW.payment_document_id;

        IF FOUND THEN
            IF source_row.invoiceable_type = 'App\Models\Contract' THEN
                PERFORM most_append_holding_payment_transaction_event_version_v1(
                    NEW,
                    source_row.invoiceable_id,
                    source_row.document_organization_id,
                    source_row.document_project_id,
                    source_row.contract_organization_id,
                    source_row.contract_project_id,
                    true,
                    occurred_value,
                    recorded_value
                );
            END IF;
        END IF;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$
SQL);
        DB::statement(
            'CREATE TRIGGER most_capture_holding_payment_transaction_v1 '
            .'AFTER INSERT OR DELETE OR UPDATE OF payment_document_id, organization_id, project_id, amount, currency, '
            .'transaction_date, value_date, status ON payment_transactions '
            .'FOR EACH ROW EXECUTE FUNCTION most_capture_holding_payment_transaction_v1()',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_payment_document_contract_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    transaction_row payment_transactions;
    old_contract_organization_id bigint;
    old_contract_project_id bigint;
    new_contract_organization_id bigint;
    new_contract_project_id bigint;
    occurred_value timestamptz;
    recorded_value timestamptz := clock_timestamp();
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.invoiceable_type = 'App\Models\Contract' AND OLD.invoiceable_id IS NOT NULL THEN
            SELECT organization_id, project_id
            INTO old_contract_organization_id, old_contract_project_id
            FROM contracts
            WHERE id = OLD.invoiceable_id;
        END IF;

        occurred_value := recorded_value;
        FOR transaction_row IN
            SELECT *
            FROM payment_transactions
            WHERE payment_document_id = OLD.id
              AND status IN ('completed', 'refunded')
        LOOP
            IF OLD.invoiceable_type = 'App\Models\Contract' THEN
                PERFORM most_append_holding_payment_transaction_event_version_v1(
                    transaction_row,
                    OLD.invoiceable_id,
                    OLD.organization_id,
                    OLD.project_id,
                    old_contract_organization_id,
                    old_contract_project_id,
                    false,
                    occurred_value,
                    recorded_value
                );
            END IF;
        END LOOP;

        RETURN OLD;
    END IF;

    IF NEW.invoiceable_type IS NOT DISTINCT FROM OLD.invoiceable_type
       AND NEW.invoiceable_id IS NOT DISTINCT FROM OLD.invoiceable_id
       AND NEW.organization_id IS NOT DISTINCT FROM OLD.organization_id
       AND NEW.project_id IS NOT DISTINCT FROM OLD.project_id THEN
        RETURN NEW;
    END IF;

    IF OLD.invoiceable_type = 'App\Models\Contract' AND OLD.invoiceable_id IS NOT NULL THEN
        SELECT organization_id, project_id
        INTO old_contract_organization_id, old_contract_project_id
        FROM contracts
        WHERE id = OLD.invoiceable_id;
    END IF;
    IF NEW.invoiceable_type = 'App\Models\Contract' AND NEW.invoiceable_id IS NOT NULL THEN
        SELECT organization_id, project_id
        INTO new_contract_organization_id, new_contract_project_id
        FROM contracts
        WHERE id = NEW.invoiceable_id;
    END IF;

    occurred_value := recorded_value;
    FOR transaction_row IN
        SELECT *
        FROM payment_transactions
        WHERE payment_document_id = NEW.id
          AND status IN ('completed', 'refunded')
    LOOP
        IF OLD.invoiceable_type = 'App\Models\Contract' THEN
            PERFORM most_append_holding_payment_transaction_event_version_v1(
                transaction_row,
                OLD.invoiceable_id,
                OLD.organization_id,
                OLD.project_id,
                old_contract_organization_id,
                old_contract_project_id,
                false,
                occurred_value,
                recorded_value
            );
        END IF;

        IF NEW.invoiceable_type = 'App\Models\Contract' THEN
            PERFORM most_append_holding_payment_transaction_event_version_v1(
                transaction_row,
                NEW.invoiceable_id,
                NEW.organization_id,
                NEW.project_id,
                new_contract_organization_id,
                new_contract_project_id,
                true,
                occurred_value,
                recorded_value
            );
        END IF;
    END LOOP;

    RETURN NEW;
END;
$$
SQL);
        DB::statement(
            'CREATE TRIGGER most_capture_holding_payment_document_contract_v1 '
            .'AFTER UPDATE OF invoiceable_type, invoiceable_id, organization_id, project_id ON payment_documents '
            .'FOR EACH ROW EXECUTE FUNCTION most_capture_holding_payment_document_contract_v1()',
        );
        DB::statement(
            'CREATE TRIGGER most_capture_holding_payment_document_delete_v1 '
            .'BEFORE DELETE ON payment_documents '
            .'FOR EACH ROW EXECUTE FUNCTION most_capture_holding_payment_document_contract_v1()',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_payment_contract_scope_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_row record;
    occurred_value timestamptz;
    recorded_value timestamptz := clock_timestamp();
BEGIN
    IF TG_OP = 'UPDATE'
       AND NEW.organization_id IS NOT DISTINCT FROM OLD.organization_id
       AND NEW.project_id IS NOT DISTINCT FROM OLD.project_id THEN
        RETURN NEW;
    END IF;

    occurred_value := recorded_value;
    FOR source_row IN
        SELECT
            transaction_row AS payment_transaction,
            document_row.organization_id AS document_organization_id,
            document_row.project_id AS document_project_id
        FROM payment_transactions AS transaction_row
        INNER JOIN payment_documents AS document_row
            ON document_row.id = transaction_row.payment_document_id
        WHERE document_row.invoiceable_type = 'App\Models\Contract'
          AND document_row.invoiceable_id = OLD.id
          AND transaction_row.status IN ('completed', 'refunded')
    LOOP
        PERFORM most_append_holding_payment_transaction_event_version_v1(
            source_row.payment_transaction,
            OLD.id,
            source_row.document_organization_id,
            source_row.document_project_id,
            OLD.organization_id,
            OLD.project_id,
            false,
            occurred_value,
            recorded_value
        );

        IF TG_OP = 'UPDATE' THEN
            PERFORM most_append_holding_payment_transaction_event_version_v1(
                source_row.payment_transaction,
                NEW.id,
                source_row.document_organization_id,
                source_row.document_project_id,
                NEW.organization_id,
                NEW.project_id,
                true,
                occurred_value,
                recorded_value
            );
        END IF;
    END LOOP;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$
SQL);
        DB::statement(
            'CREATE TRIGGER most_capture_holding_payment_contract_scope_v1 '
            .'AFTER DELETE OR UPDATE OF organization_id, project_id ON contracts '
            .'FOR EACH ROW EXECUTE FUNCTION most_capture_holding_payment_contract_scope_v1()',
        );

        DB::unprepared(<<<'SQL'
DO $$
DECLARE
    checkpoint_at timestamptz := clock_timestamp();
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
        DB::statement(
            'DROP TRIGGER IF EXISTS most_capture_holding_payment_contract_scope_v1 ON contracts',
        );
        DB::statement('DROP FUNCTION IF EXISTS most_capture_holding_payment_contract_scope_v1()');
        DB::statement(
            'DROP TRIGGER IF EXISTS most_capture_holding_payment_document_delete_v1 ON payment_documents',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS most_capture_holding_payment_document_contract_v1 ON payment_documents',
        );
        DB::statement('DROP FUNCTION IF EXISTS most_capture_holding_payment_document_contract_v1()');
        DB::statement(
            'DROP TRIGGER IF EXISTS most_capture_holding_payment_transaction_v1 ON payment_transactions',
        );
        DB::statement('DROP FUNCTION IF EXISTS most_capture_holding_payment_transaction_v1()');
        DB::statement(
            'DROP FUNCTION IF EXISTS most_append_holding_payment_transaction_event_version_v1('
            .'payment_transactions, bigint, bigint, bigint, bigint, bigint, boolean, '
            .'timestamp with time zone, timestamp with time zone)',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS holding_payment_coverage_append_only '
            .'ON holding_payment_event_coverage_checkpoints',
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS holding_payment_event_versions_append_only '
            .'ON holding_payment_transaction_event_versions',
        );
        DB::statement('DROP FUNCTION IF EXISTS holding_payment_event_evidence_append_only()');
        Schema::dropIfExists('holding_payment_transaction_event_versions');
        Schema::dropIfExists('holding_payment_event_coverage_checkpoints');
    }
};
