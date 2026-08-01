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
    (event_code = 'cancelled' AND terminal_reason IN ('request_rejected', 'request_cancelled', 'order_cancelled'))
    OR (event_code <> 'cancelled' AND terminal_reason IS NULL)
),
ADD CONSTRAINT procurement_process_event_hash_check
CHECK (payload_hash ~ '^[a-f0-9]{64}$'),
ADD CONSTRAINT procurement_process_event_policy_pins_check
CHECK (
    (policy_version_id IS NULL AND policy_hash IS NULL AND calendar_version IS NULL AND calendar_hash IS NULL)
    OR (
        policy_version_id IS NOT NULL
        AND policy_hash ~ '^[a-f0-9]{64}$'
        AND calendar_version = 'procurement-business-calendar.v1'
        AND calendar_hash ~ '^[a-f0-9]{64}$'
    )
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
    pinned_policy procurement_cycle_policy_versions%ROWTYPE;
BEGIN
    SELECT purchase_requests.organization_id, site_requests.project_id
    INTO request_organization_id, request_project_id
    FROM purchase_request_lines
    INNER JOIN purchase_requests ON purchase_requests.id = purchase_request_lines.purchase_request_id
    LEFT JOIN site_requests ON site_requests.id = purchase_requests.site_request_id
    WHERE purchase_request_lines.id = NEW.purchase_request_line_id
      AND purchase_requests.id = NEW.purchase_request_id;

    IF NOT FOUND
       OR request_organization_id <> NEW.organization_id
       OR request_project_id IS DISTINCT FROM NEW.project_id THEN
        RAISE EXCEPTION 'procurement process event lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_request_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_requests
        WHERE id = NEW.supplier_request_id
          AND organization_id = NEW.organization_id
          AND purchase_request_id = NEW.purchase_request_id
    ) THEN
        RAISE EXCEPTION 'procurement process supplier request lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_request_line_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_request_lines
        WHERE id = NEW.supplier_request_line_id
          AND supplier_request_id = NEW.supplier_request_id
          AND purchase_request_line_id = NEW.purchase_request_line_id
    ) THEN
        RAISE EXCEPTION 'procurement process supplier request line lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_party_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_parties
        WHERE id = NEW.supplier_party_id
          AND organization_id = NEW.organization_id
    ) THEN
        RAISE EXCEPTION 'procurement process supplier party lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_proposal_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_proposals
        WHERE id = NEW.supplier_proposal_id
          AND organization_id = NEW.organization_id
          AND supplier_request_id = NEW.supplier_request_id
    ) THEN
        RAISE EXCEPTION 'procurement process supplier proposal lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.supplier_proposal_version_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM supplier_proposal_versions
        WHERE id = NEW.supplier_proposal_version_id
          AND organization_id = NEW.organization_id
          AND supplier_proposal_id = NEW.supplier_proposal_id
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
    ) THEN
        RAISE EXCEPTION 'procurement process proposal decision lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_order_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_orders
        WHERE id = NEW.purchase_order_id
          AND organization_id = NEW.organization_id
          AND purchase_request_id = NEW.purchase_request_id
    ) THEN
        RAISE EXCEPTION 'procurement process order lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_order_item_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_order_items
        WHERE id = NEW.purchase_order_item_id
          AND purchase_order_id = NEW.purchase_order_id
          AND purchase_request_line_id = NEW.purchase_request_line_id
          AND supplier_request_line_id IS NOT DISTINCT FROM NEW.supplier_request_line_id
    ) THEN
        RAISE EXCEPTION 'procurement process order item lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_receipt_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_receipts
        WHERE id = NEW.purchase_receipt_id
          AND purchase_order_id = NEW.purchase_order_id
    ) THEN
        RAISE EXCEPTION 'procurement process receipt lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.purchase_receipt_line_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_receipt_lines
        WHERE id = NEW.purchase_receipt_line_id
          AND purchase_receipt_id = NEW.purchase_receipt_id
          AND purchase_order_item_id = NEW.purchase_order_item_id
    ) THEN
        RAISE EXCEPTION 'procurement process receipt line lineage mismatch' USING ERRCODE = '23514';
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
