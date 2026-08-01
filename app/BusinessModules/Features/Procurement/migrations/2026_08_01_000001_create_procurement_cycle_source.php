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
        Schema::create('procurement_cycle_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('formula_version', 64);
            $table->string('source_schema_version', 32);
            $table->string('event_schema_version', 64);
            $table->string('calendar_version', 64);
            $table->char('calendar_hash', 64);
            $table->string('timezone', 64);
            $table->jsonb('weekly_windows');
            $table->jsonb('exceptions');
            $table->jsonb('stage_sla_seconds');
            $table->unsignedBigInteger('total_sla_seconds');
            $table->jsonb('terminal_cancellation_policy');
            $table->timestampTz('effective_from', 6);
            $table->timestampTz('effective_to', 6)->nullable();
            $table->char('canonical_hash', 64);
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at', 6);
            $table->timestampTz('created_at', 6);

            $table->index(
                ['organization_id', 'project_id', 'effective_from'],
                'proc_cycle_policy_scope_effective_idx',
            );
            $table->index(['organization_id', 'canonical_hash'], 'proc_cycle_policy_hash_idx');
        });

        Schema::create('procurement_process_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $table->foreignId('purchase_request_line_id')->constrained('purchase_request_lines')->restrictOnDelete();
            $table->foreignId('supplier_request_id')->nullable()->constrained('supplier_requests')->restrictOnDelete();
            $table->foreignId('supplier_request_line_id')->nullable()->constrained('supplier_request_lines')->restrictOnDelete();
            $table->foreignId('supplier_party_id')->nullable()->constrained('supplier_parties')->restrictOnDelete();
            $table->foreignId('supplier_proposal_id')->nullable()->constrained('supplier_proposals')->restrictOnDelete();
            $table->foreignId('supplier_proposal_version_id')->nullable()->constrained('supplier_proposal_versions')->restrictOnDelete();
            $table->foreignId('supplier_proposal_decision_id')->nullable()->constrained('supplier_proposal_decisions')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('purchase_receipt_id')->nullable()->constrained('purchase_receipts')->restrictOnDelete();
            $table->foreignId('purchase_receipt_line_id')->nullable()->constrained('purchase_receipt_lines')->restrictOnDelete();
            $table->foreignId('policy_version_id')->nullable()->constrained('procurement_cycle_policy_versions')->restrictOnDelete();
            $table->char('policy_hash', 64)->nullable();
            $table->string('calendar_version', 64)->nullable();
            $table->char('calendar_hash', 64)->nullable();
            $table->string('event_code', 48);
            $table->string('terminal_reason', 48)->nullable();
            $table->string('event_version', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at', 6);
            $table->string('source_kind', 64);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('source_event_id')->nullable()->constrained('procurement_audit_events')->restrictOnDelete();
            $table->jsonb('dimension_snapshot');
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at', 6);

            $table->unique(
                [
                    'organization_id',
                    'purchase_request_line_id',
                    'event_code',
                    'source_kind',
                    'source_id',
                    'event_version',
                ],
                'proc_process_event_source_unique',
            );
            $table->index(
                ['organization_id', 'purchase_request_line_id', 'occurred_at', 'id'],
                'proc_process_event_line_timeline_idx',
            );
            $table->index(
                ['organization_id', 'project_id', 'event_code'],
                'proc_process_event_scope_code_idx',
            );
            $table->index(
                ['organization_id', 'event_code', 'occurred_at'],
                'proc_process_event_cohort_idx',
            );
            $table->index(['policy_version_id', 'purchase_request_line_id'], 'proc_process_event_policy_idx');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->foreignId('purchase_request_line_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('purchase_request_lines')
                ->restrictOnDelete();
            $table->foreignId('supplier_request_line_id')
                ->nullable()
                ->after('purchase_request_line_id')
                ->constrained('supplier_request_lines')
                ->restrictOnDelete();
            $table->foreignId('supplier_proposal_line_id')
                ->nullable()
                ->after('supplier_request_line_id')
                ->constrained('supplier_proposal_lines')
                ->restrictOnDelete();
            $table->index(
                ['purchase_order_id', 'purchase_request_line_id'],
                'purchase_order_items_request_line_idx',
            );
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->timestampTz('sent_at_exact', 6)->nullable()->after('sent_at');
            $table->index(['organization_id', 'sent_at_exact'], 'purchase_orders_sent_exact_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX procurement_cycle_policy_scope_version_unique
ON procurement_cycle_policy_versions (organization_id, COALESCE(project_id, 0), version_number)
SQL);
            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX procurement_process_event_receipt_milestone_unique
ON procurement_process_events (organization_id, purchase_request_line_id, event_code)
WHERE event_code IN ('first_receipt', 'fully_received')
SQL);
            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX procurement_process_event_request_created_unique
ON procurement_process_events (organization_id, purchase_request_line_id)
WHERE event_code = 'request_created'
SQL);
            DB::statement(<<<'SQL'
ALTER TABLE procurement_cycle_policy_versions
ADD CONSTRAINT procurement_cycle_policy_hash_check
CHECK (canonical_hash ~ '^[a-f0-9]{64}$' AND calendar_hash ~ '^[a-f0-9]{64}$'),
ADD CONSTRAINT procurement_cycle_policy_versions_check
CHECK (
    formula_version = 'procurement-cycle.v1'
    AND source_schema_version = '1.0.0'
    AND event_schema_version = 'procurement-process-events.v1'
    AND calendar_version = 'procurement-business-calendar.v1'
    AND version_number > 0
    AND total_sla_seconds > 0
    AND (effective_to IS NULL OR effective_to > effective_from)
)
SQL);
            DB::statement(<<<'SQL'
ALTER TABLE procurement_process_events
ADD CONSTRAINT procurement_process_event_code_check
CHECK (event_code IN (
    'request_created',
    'request_approved',
    'solicitation_sent',
    'supplier_responded',
    'award_decided',
    'order_sent',
    'first_receipt',
    'fully_received',
    'cancelled'
)),
ADD CONSTRAINT procurement_process_event_schema_check
CHECK (event_version = 'procurement-process-events.v1'),
ADD CONSTRAINT procurement_process_event_terminal_reason_check
CHECK (
    (event_code = 'cancelled'
        AND terminal_reason IS NOT NULL
        AND terminal_reason IN ('request_rejected', 'request_cancelled', 'order_cancelled'))
    OR (event_code <> 'cancelled' AND terminal_reason IS NULL)
),
ADD CONSTRAINT procurement_process_event_hash_check
CHECK (payload_hash ~ '^[a-f0-9]{64}$'),
ADD CONSTRAINT procurement_process_event_supplier_proposal_pair_check
CHECK ((supplier_proposal_id IS NULL) = (supplier_proposal_version_id IS NULL)),
ADD CONSTRAINT procurement_process_event_policy_pins_check
CHECK (
    (policy_version_id IS NULL AND policy_hash IS NULL AND calendar_version IS NULL AND calendar_hash IS NULL)
    OR (
        policy_version_id IS NOT NULL
        AND policy_hash IS NOT NULL
        AND policy_hash ~ '^[a-f0-9]{64}$'
        AND calendar_version IS NOT NULL
        AND calendar_version = 'procurement-business-calendar.v1'
        AND calendar_hash IS NOT NULL
        AND calendar_hash ~ '^[a-f0-9]{64}$'
    )
),
ADD CONSTRAINT procurement_process_event_dimension_quality_check
CHECK (
    jsonb_typeof(dimension_snapshot->'gap_codes') IS NOT DISTINCT FROM 'array'
    AND (
        (dimension_snapshot->>'quality_status' = 'FULL'
            AND jsonb_array_length(dimension_snapshot->'gap_codes') = 0)
        OR (dimension_snapshot->>'quality_status' = 'PARTIAL'
            AND jsonb_array_length(dimension_snapshot->'gap_codes') > 0)
    ) IS TRUE
),
ADD CONSTRAINT procurement_process_event_dimension_lineage_check
CHECK (
    dimension_snapshot @> jsonb_build_object(
        'schema_version', 'procurement-process-dimensions.v1',
        'organization_id', organization_id,
        'project_id', project_id,
        'purchase_request_id', purchase_request_id,
        'purchase_request_line_id', purchase_request_line_id
    )
)
SQL);
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION procurement_cycle_policy_validate_scope()
RETURNS trigger AS $$
BEGIN
    IF NEW.project_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM projects
        WHERE id = NEW.project_id
          AND organization_id = NEW.organization_id
    ) THEN
        RAISE EXCEPTION 'procurement cycle policy project scope mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
            DB::statement(
                'CREATE TRIGGER procurement_cycle_policy_versions_validate_scope '
                .'BEFORE INSERT ON procurement_cycle_policy_versions '
                .'FOR EACH ROW EXECUTE FUNCTION procurement_cycle_policy_validate_scope()',
            );
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION procurement_process_event_validate_lineage()
RETURNS trigger AS $$
DECLARE
    request_organization_id bigint;
    request_project_id bigint;
    request_site_request_id bigint;
    created_event procurement_process_events%ROWTYPE;
    pinned_policy procurement_cycle_policy_versions%ROWTYPE;
BEGIN
    SELECT purchase_requests.organization_id
    INTO request_organization_id
    FROM purchase_request_lines
    INNER JOIN purchase_requests ON purchase_requests.id = purchase_request_lines.purchase_request_id
    WHERE purchase_request_lines.id = NEW.purchase_request_line_id
      AND purchase_requests.id = NEW.purchase_request_id
    FOR KEY SHARE OF purchase_request_lines, purchase_requests;

    IF NOT FOUND
       OR request_organization_id <> NEW.organization_id THEN
        RAISE EXCEPTION 'procurement process event lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_code = 'request_created' THEN
        SELECT purchase_requests.site_request_id
        INTO request_site_request_id
        FROM purchase_requests
        WHERE purchase_requests.id = NEW.purchase_request_id
        FOR SHARE;

        IF request_site_request_id IS NULL THEN
            request_project_id := NULL;
        ELSE
            SELECT site_requests.project_id
            INTO request_project_id
            FROM site_requests
            WHERE site_requests.id = request_site_request_id
            FOR SHARE;

            IF NOT FOUND THEN
                RAISE EXCEPTION 'procurement process request-created site request lineage mismatch' USING ERRCODE = '23514';
            END IF;
        END IF;

        IF request_project_id IS DISTINCT FROM NEW.project_id THEN
            RAISE EXCEPTION 'procurement process request-created project lineage mismatch' USING ERRCODE = '23514';
        END IF;
    ELSE
        SELECT * INTO created_event
        FROM procurement_process_events
        WHERE organization_id = NEW.organization_id
          AND purchase_request_line_id = NEW.purchase_request_line_id
          AND event_code = 'request_created'
        ORDER BY occurred_at, id
        LIMIT 1;

        IF FOUND THEN
            IF created_event.project_id IS DISTINCT FROM NEW.project_id
               OR created_event.policy_version_id IS DISTINCT FROM NEW.policy_version_id
               OR created_event.policy_hash IS DISTINCT FROM NEW.policy_hash
               OR created_event.calendar_version IS DISTINCT FROM NEW.calendar_version
               OR created_event.calendar_hash IS DISTINCT FROM NEW.calendar_hash THEN
                RAISE EXCEPTION 'procurement process event request-created provenance mismatch' USING ERRCODE = '23514';
            END IF;
        ELSIF NEW.project_id IS NOT NULL
           OR NEW.policy_version_id IS NOT NULL
           OR NEW.policy_hash IS NOT NULL
           OR NEW.calendar_version IS NOT NULL
           OR NEW.calendar_hash IS NOT NULL
           OR NEW.dimension_snapshot->>'quality_status' IS DISTINCT FROM 'PARTIAL'
           OR jsonb_typeof(NEW.dimension_snapshot->'gap_codes') IS DISTINCT FROM 'array'
           OR (NEW.dimension_snapshot->'gap_codes' @> '["missing_request_created_event", "missing_project_lineage", "missing_policy_version"]'::jsonb) IS NOT TRUE
           OR (NEW.dimension_snapshot - ARRAY[
               'schema_version',
               'organization_id',
               'project_id',
               'purchase_request_id',
               'purchase_request_line_id',
               'quality_status',
               'gap_codes'
           ]) <> '{}'::jsonb THEN
            RAISE EXCEPTION 'procurement process event quarantine provenance required' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF (NEW.supplier_request_id IS NOT NULL
        OR NEW.supplier_proposal_id IS NOT NULL
        OR NEW.purchase_order_id IS NOT NULL)
       AND NEW.supplier_party_id IS NULL THEN
        RAISE EXCEPTION 'procurement process supplier party required' USING ERRCODE = '23514';
    END IF;

    IF (NEW.supplier_proposal_id IS NULL) <> (NEW.supplier_proposal_version_id IS NULL) THEN
        RAISE EXCEPTION 'procurement process supplier proposal version pair required' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_request_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_requests
        WHERE id = NEW.supplier_request_id
          AND organization_id = NEW.organization_id
          AND purchase_request_id = NEW.purchase_request_id
          AND supplier_party_id = NEW.supplier_party_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement process supplier request lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_request_line_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_request_lines
        WHERE id = NEW.supplier_request_line_id
          AND supplier_request_id = NEW.supplier_request_id
          AND purchase_request_line_id = NEW.purchase_request_line_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement process supplier request line lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_party_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_parties
        WHERE id = NEW.supplier_party_id
          AND organization_id = NEW.organization_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement process supplier party lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_proposal_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM supplier_proposals
        INNER JOIN supplier_requests ON supplier_requests.id = supplier_proposals.supplier_request_id
        WHERE supplier_proposals.id = NEW.supplier_proposal_id
          AND supplier_proposals.organization_id = NEW.organization_id
          AND supplier_proposals.supplier_request_id = NEW.supplier_request_id
          AND supplier_proposals.supplier_party_id = NEW.supplier_party_id
          AND supplier_requests.purchase_request_id = NEW.purchase_request_id
          AND supplier_requests.supplier_party_id = NEW.supplier_party_id
        FOR KEY SHARE OF supplier_proposals, supplier_requests
    ) THEN
        RAISE EXCEPTION 'procurement process supplier proposal lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_proposal_version_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM supplier_proposal_versions
        INNER JOIN supplier_proposals ON supplier_proposals.id = supplier_proposal_versions.supplier_proposal_id
        INNER JOIN supplier_requests ON supplier_requests.id = supplier_proposals.supplier_request_id
        WHERE supplier_proposal_versions.id = NEW.supplier_proposal_version_id
          AND supplier_proposal_versions.organization_id = NEW.organization_id
          AND supplier_proposal_versions.supplier_proposal_id = NEW.supplier_proposal_id
          AND supplier_proposals.supplier_request_id = NEW.supplier_request_id
          AND supplier_proposals.supplier_party_id = NEW.supplier_party_id
          AND supplier_requests.purchase_request_id = NEW.purchase_request_id
        FOR KEY SHARE OF supplier_proposal_versions, supplier_proposals, supplier_requests
    ) THEN
        RAISE EXCEPTION 'procurement process proposal version lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_proposal_decision_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_proposal_decisions
        WHERE id = NEW.supplier_proposal_decision_id
          AND organization_id = NEW.organization_id
          AND supplier_request_id = NEW.supplier_request_id
          AND winning_supplier_proposal_id = NEW.supplier_proposal_id
          AND winning_supplier_proposal_version_id = NEW.supplier_proposal_version_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement process proposal decision lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_order_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_orders
        WHERE id = NEW.purchase_order_id
          AND organization_id = NEW.organization_id
          AND purchase_request_id = NEW.purchase_request_id
          AND supplier_party_id = NEW.supplier_party_id
          AND accepted_supplier_proposal_id IS NOT DISTINCT FROM NEW.supplier_proposal_id
          AND accepted_supplier_proposal_version_id IS NOT DISTINCT FROM NEW.supplier_proposal_version_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement process order lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_order_item_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_order_items
        WHERE purchase_order_items.id = NEW.purchase_order_item_id
          AND purchase_order_items.purchase_order_id = NEW.purchase_order_id
          AND purchase_order_items.purchase_request_line_id = NEW.purchase_request_line_id
          AND purchase_order_items.supplier_request_line_id IS NOT DISTINCT FROM NEW.supplier_request_line_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement process order item lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_order_item_id IS NOT NULL
       AND NEW.supplier_proposal_id IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM purchase_order_items
           WHERE id = NEW.purchase_order_item_id
             AND supplier_proposal_line_id IS NULL
           FOR KEY SHARE
       ) THEN
        RAISE EXCEPTION 'procurement process direct order item lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_order_item_id IS NOT NULL
       AND NEW.supplier_proposal_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1
           FROM purchase_order_items
           INNER JOIN supplier_proposal_lines
             ON supplier_proposal_lines.id = purchase_order_items.supplier_proposal_line_id
           WHERE purchase_order_items.id = NEW.purchase_order_item_id
             AND supplier_proposal_lines.supplier_proposal_id = NEW.supplier_proposal_id
             AND supplier_proposal_lines.supplier_request_line_id = NEW.supplier_request_line_id
           FOR KEY SHARE OF purchase_order_items, supplier_proposal_lines
       ) THEN
        RAISE EXCEPTION 'procurement process proposal line lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_receipt_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_receipts
        WHERE id = NEW.purchase_receipt_id
          AND organization_id = NEW.organization_id
          AND purchase_order_id = NEW.purchase_order_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement process receipt lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_receipt_line_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_receipt_lines
        WHERE id = NEW.purchase_receipt_line_id
          AND purchase_receipt_id = NEW.purchase_receipt_id
          AND purchase_order_item_id = NEW.purchase_order_item_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement process receipt line lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_code = 'cancelled'
       AND NEW.policy_version_id IS NULL THEN
        RAISE EXCEPTION 'procurement process unpinned terminal reason is not permitted' USING ERRCODE = '23514';
    END IF;

    IF NEW.policy_version_id IS NOT NULL THEN
        SELECT * INTO pinned_policy
        FROM procurement_cycle_policy_versions
        WHERE id = NEW.policy_version_id;

        IF NOT FOUND
           OR pinned_policy.organization_id <> NEW.organization_id
           OR (pinned_policy.project_id IS NOT NULL AND pinned_policy.project_id IS DISTINCT FROM NEW.project_id)
           OR pinned_policy.canonical_hash <> NEW.policy_hash
           OR pinned_policy.calendar_version <> NEW.calendar_version
           OR pinned_policy.calendar_hash <> NEW.calendar_hash
           OR pinned_policy.effective_from > NEW.occurred_at
           OR (pinned_policy.effective_to IS NOT NULL AND pinned_policy.effective_to <= NEW.occurred_at) THEN
            RAISE EXCEPTION 'procurement process event policy pin mismatch' USING ERRCODE = '23514';
        END IF;

        IF NEW.event_code = 'cancelled'
           AND NOT (pinned_policy.terminal_cancellation_policy @> jsonb_build_array(NEW.terminal_reason)) THEN
            RAISE EXCEPTION 'procurement process terminal reason is not allowed by pinned policy' USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
            DB::statement(
                'CREATE TRIGGER procurement_process_events_validate_lineage '
                .'BEFORE INSERT ON procurement_process_events '
                .'FOR EACH ROW EXECUTE FUNCTION procurement_process_event_validate_lineage()',
            );
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION procurement_reporting_prevent_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'procurement reporting source is append-only' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql
SQL);
            DB::statement(
                'CREATE TRIGGER procurement_process_events_append_only '
                .'BEFORE UPDATE OR DELETE ON procurement_process_events '
                .'FOR EACH ROW EXECUTE FUNCTION procurement_reporting_prevent_mutation()',
            );
            DB::statement(
                'CREATE TRIGGER procurement_cycle_policy_versions_append_only '
                .'BEFORE UPDATE OR DELETE ON procurement_cycle_policy_versions '
                .'FOR EACH ROW EXECUTE FUNCTION procurement_reporting_prevent_mutation()',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'DROP TRIGGER IF EXISTS procurement_process_events_append_only ON procurement_process_events',
            );
            DB::statement(
                'DROP TRIGGER IF EXISTS procurement_process_events_validate_lineage ON procurement_process_events',
            );
            DB::statement(
                'DROP TRIGGER IF EXISTS procurement_cycle_policy_versions_append_only '
                .'ON procurement_cycle_policy_versions',
            );
            DB::statement(
                'DROP TRIGGER IF EXISTS procurement_cycle_policy_versions_validate_scope '
                .'ON procurement_cycle_policy_versions',
            );
            DB::statement('DROP FUNCTION IF EXISTS procurement_reporting_prevent_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS procurement_process_event_validate_lineage()');
            DB::statement('DROP FUNCTION IF EXISTS procurement_cycle_policy_validate_scope()');
            DB::statement('DROP INDEX IF EXISTS procurement_cycle_policy_scope_version_unique');
            DB::statement('DROP INDEX IF EXISTS procurement_process_event_receipt_milestone_unique');
            DB::statement('DROP INDEX IF EXISTS procurement_process_event_request_created_unique');
        }

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('purchase_orders_sent_exact_idx');
            $table->dropColumn('sent_at_exact');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropIndex('purchase_order_items_request_line_idx');
            $table->dropConstrainedForeignId('supplier_proposal_line_id');
            $table->dropConstrainedForeignId('supplier_request_line_id');
            $table->dropConstrainedForeignId('purchase_request_line_id');
        });

        Schema::dropIfExists('procurement_process_events');
        Schema::dropIfExists('procurement_cycle_policy_versions');
    }
};
