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
        Schema::create('workforce_payroll_readiness_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('snapshot_kind', 40);
            $table->string('result_code', 40);
            $table->string('reason_code', 80);
            $table->unsignedBigInteger('actor_user_id');
            $table->timestampTz('evaluated_at');
            $table->string('schema_version', 80);
            $table->string('formula_version', 80);
            $table->string('policy_version', 80);
            $table->char('policy_hash', 64);
            $table->jsonb('policy_definition');
            $table->char('owner_source_hash', 64);
            $table->char('locked_source_hash', 64)->nullable();
            $table->char('state_hash', 64);
            $table->char('items_hash', 64);
            $table->char('source_hash', 64);
            $table->jsonb('blocker_codes');
            $table->jsonb('gap_codes');
            $table->unsignedBigInteger('source_row_count');
            $table->unsignedBigInteger('validation_issue_count');
            $table->unsignedBigInteger('blocker_count');
            $table->unsignedBigInteger('item_count');
            $table->timestampTz('created_at');

            $table->unique(
                ['organization_id', 'payroll_period_id', 'source_hash', 'snapshot_kind'],
                'workforce_payroll_readiness_source_unique',
            );
            $table->index(
                ['organization_id', 'payroll_period_id', 'evaluated_at'],
                'workforce_payroll_readiness_period_index',
            );
        });

        Schema::create('workforce_payroll_readiness_snapshot_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->restrictOnDelete();
            $table->foreignId('payroll_readiness_snapshot_id')
                ->constrained('workforce_payroll_readiness_snapshots')
                ->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('evidence_code', 120);
            $table->string('evidence_status', 40);
            $table->char('content_hash', 64);
            $table->jsonb('lineage');
            $table->timestampTz('created_at');

            $table->unique(
                ['payroll_readiness_snapshot_id', 'position'],
                'workforce_payroll_readiness_item_position_unique',
            );
            $table->index(
                ['organization_id', 'payroll_period_id', 'source_type'],
                'workforce_payroll_readiness_item_period_index',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE FUNCTION workforce_payroll_readiness_prevent_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'payroll readiness evidence is append-only';
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_payroll_readiness_snapshot_lineage_guard() RETURNS trigger AS $$
DECLARE
    payroll_period workforce_payroll_periods%ROWTYPE;
BEGIN
    SELECT * INTO payroll_period
      FROM workforce_payroll_periods
     WHERE id = NEW.payroll_period_id;

    IF NOT FOUND
       OR payroll_period.organization_id <> NEW.organization_id
       OR payroll_period.project_id IS DISTINCT FROM NEW.project_id
       OR payroll_period.period_start IS DISTINCT FROM NEW.period_start
       OR payroll_period.period_end IS DISTINCT FROM NEW.period_end THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness period lineage mismatch';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM organization_user
         WHERE organization_id = NEW.organization_id
           AND user_id = NEW.actor_user_id
           AND is_active = TRUE
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness actor lineage mismatch';
    END IF;

    IF NEW.schema_version <> 'payroll-readiness-source.v1'
       OR NEW.formula_version <> 'payroll-readiness-checks.v1'
       OR NEW.policy_version <> 'payroll-readiness-policy.v1'
       OR NEW.policy_hash <> '7109e886ffb25a26311b107d4965630d9b7e075c8e69b156bca8594b61a43283'
       OR NEW.policy_definition <> '{"version":"payroll-readiness-policy.v1","timezone":"UTC","calendar_mode":"none","check_order":["period_validated","source_present","source_actual","validation_clear","accounting_clear"],"allowed_reasons":["period_not_validated","source_empty","source_changed","validation_blockers","accounting_blockers","locked"],"blocking_severities":["blocking"],"redacted_fields":["employee_id","employee_name","hours","amount","message","personnel_number","salary_amount"]}'::jsonb
       OR NEW.reason_code NOT IN (
           'period_not_validated',
           'source_empty',
           'source_changed',
           'validation_blockers',
           'accounting_blockers',
           'locked'
       )
       OR NEW.policy_hash !~ '^[0-9a-f]{64}$'
       OR NEW.owner_source_hash !~ '^[0-9a-f]{64}$'
       OR NEW.state_hash !~ '^[0-9a-f]{64}$'
       OR NEW.items_hash !~ '^[0-9a-f]{64}$'
       OR NEW.source_hash !~ '^[0-9a-f]{64}$'
       OR (NEW.locked_source_hash IS NOT NULL AND NEW.locked_source_hash !~ '^[0-9a-f]{64}$')
       OR jsonb_typeof(NEW.policy_definition) <> 'object'
       OR jsonb_typeof(NEW.blocker_codes) <> 'array'
       OR jsonb_typeof(NEW.gap_codes) <> 'array'
       OR NEW.item_count < 5
       OR NEW.item_count <> 5 + NEW.source_row_count + NEW.validation_issue_count
       OR NEW.blocker_count > NEW.validation_issue_count THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness snapshot payload invalid';
    END IF;

    IF NEW.snapshot_kind = 'lock_succeeded' THEN
        IF NEW.result_code <> 'locked'
           OR NEW.reason_code <> 'locked'
           OR NEW.locked_source_hash IS NULL
           OR NEW.owner_source_hash <> NEW.locked_source_hash
           OR payroll_period.status <> 'locked'
           OR payroll_period.source_hash IS DISTINCT FROM NEW.locked_source_hash
           OR payroll_period.locked_by_user_id IS DISTINCT FROM NEW.actor_user_id
           OR payroll_period.locked_at IS DISTINCT FROM NEW.evaluated_at
           OR jsonb_array_length(NEW.blocker_codes) <> 0
           OR jsonb_array_length(NEW.gap_codes) <> 0
           OR NEW.source_row_count < 1
           OR NEW.validation_issue_count <> 0
           OR NEW.blocker_count <> 0 THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness locked snapshot mismatch';
        END IF;
    ELSIF NEW.snapshot_kind = 'pre_lock_blocked' THEN
        IF NEW.result_code <> 'blocked'
           OR NEW.reason_code NOT IN (
               'period_not_validated',
               'source_empty',
               'source_changed',
               'validation_blockers',
               'accounting_blockers'
           )
           OR NEW.locked_source_hash IS NOT NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness blocked snapshot mismatch';
        END IF;
    ELSE
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness snapshot kind invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_payroll_readiness_item_lineage_guard() RETURNS trigger AS $$
DECLARE
    snapshot workforce_payroll_readiness_snapshots%ROWTYPE;
    source_row workforce_payroll_source_rows%ROWTYPE;
    validation_issue workforce_payroll_validation_issues%ROWTYPE;
BEGIN
    SELECT * INTO snapshot
      FROM workforce_payroll_readiness_snapshots
     WHERE id = NEW.payroll_readiness_snapshot_id;

    IF NOT FOUND
       OR snapshot.organization_id <> NEW.organization_id
       OR snapshot.payroll_period_id <> NEW.payroll_period_id THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness item snapshot lineage mismatch';
    END IF;

    IF NEW.content_hash !~ '^[0-9a-f]{64}$'
       OR jsonb_typeof(NEW.lineage) <> 'object'
       OR NEW.lineage ?| ARRAY[
           'employee_id',
           'employee_name',
           'hours',
           'amount',
           'message',
           'personnel_number',
           'salary_amount'
       ] THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness item payload invalid';
    END IF;

    IF NEW.source_type = 'readiness_check' THEN
        IF NEW.source_id IS NOT NULL
           OR NEW.position NOT BETWEEN 1 AND 5
           OR NEW.evidence_code IS DISTINCT FROM (
               ARRAY['period_validated', 'source_present', 'source_actual', 'validation_clear', 'accounting_clear']
           )[NEW.position]
           OR NEW.evidence_status NOT IN ('passed', 'blocked', 'not_evaluated')
           OR NEW.lineage <> '{}'::jsonb THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness check lineage mismatch';
        END IF;
    ELSIF NEW.source_type = 'payroll_source_row' THEN
        SELECT * INTO source_row
          FROM workforce_payroll_source_rows
         WHERE id = NEW.source_id;

        IF NOT FOUND
           OR NEW.position <= 5
           OR NEW.evidence_status <> 'captured'
           OR NOT (NEW.lineage ?& ARRAY[
               'payroll_period_id',
               'project_id',
               'work_order_id',
               'work_order_line_id',
               'timesheet_entry_id',
               'work_date'
           ])
           OR NEW.lineage - ARRAY[
               'payroll_period_id',
               'project_id',
               'work_order_id',
               'work_order_line_id',
               'timesheet_entry_id',
               'work_date'
           ] <> '{}'::jsonb
           OR source_row.organization_id <> NEW.organization_id
           OR source_row.payroll_period_id <> NEW.payroll_period_id
           OR (snapshot.project_id IS NOT NULL AND source_row.project_id IS DISTINCT FROM snapshot.project_id)
           OR (NEW.lineage->>'payroll_period_id')::bigint IS DISTINCT FROM source_row.payroll_period_id
           OR (NEW.lineage->>'project_id')::bigint IS DISTINCT FROM source_row.project_id
           OR NULLIF(NEW.lineage->>'work_order_id', '')::bigint IS DISTINCT FROM source_row.work_order_id
           OR NULLIF(NEW.lineage->>'work_order_line_id', '')::bigint IS DISTINCT FROM source_row.work_order_line_id
           OR NULLIF(NEW.lineage->>'timesheet_entry_id', '')::bigint IS DISTINCT FROM source_row.timesheet_entry_id
           OR (NEW.lineage->>'work_date')::date IS DISTINCT FROM source_row.work_date THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness source row lineage mismatch';
        END IF;
    ELSIF NEW.source_type = 'validation_issue' THEN
        SELECT * INTO validation_issue
          FROM workforce_payroll_validation_issues
         WHERE id = NEW.source_id;

        IF NOT FOUND
           OR NEW.position <= 5
           OR NOT (NEW.lineage ?& ARRAY[
               'payroll_period_id',
               'project_id',
               'entity_type',
               'entity_id'
           ])
           OR NEW.lineage - ARRAY[
               'payroll_period_id',
               'project_id',
               'entity_type',
               'entity_id'
           ] <> '{}'::jsonb
           OR validation_issue.organization_id <> NEW.organization_id
           OR validation_issue.payroll_period_id <> NEW.payroll_period_id
           OR validation_issue.issue_code <> NEW.evidence_code
           OR validation_issue.severity <> NEW.evidence_status
           OR (snapshot.project_id IS NOT NULL
               AND validation_issue.project_id IS NOT NULL
               AND validation_issue.project_id IS DISTINCT FROM snapshot.project_id)
           OR (NEW.lineage->>'payroll_period_id')::bigint IS DISTINCT FROM validation_issue.payroll_period_id
           OR NULLIF(NEW.lineage->>'project_id', '')::bigint IS DISTINCT FROM validation_issue.project_id
           OR NEW.lineage->>'entity_type' IS DISTINCT FROM validation_issue.entity_type
           OR NULLIF(NEW.lineage->>'entity_id', '')::bigint IS DISTINCT FROM validation_issue.entity_id THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness validation issue lineage mismatch';
        END IF;
    ELSE
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness evidence type invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_payroll_readiness_complete_guard() RETURNS trigger AS $$
DECLARE
    snapshot_id bigint;
    snapshot workforce_payroll_readiness_snapshots%ROWTYPE;
    actual_item_count bigint;
    actual_position_count bigint;
    first_position integer;
    last_position integer;
    actual_check_count bigint;
    actual_source_count bigint;
    actual_validation_count bigint;
    actual_blocker_count bigint;
BEGIN
    IF TG_TABLE_NAME = 'workforce_payroll_readiness_snapshots' THEN
        snapshot_id := NEW.id;
    ELSE
        snapshot_id := NEW.payroll_readiness_snapshot_id;
    END IF;

    SELECT * INTO snapshot
      FROM workforce_payroll_readiness_snapshots
     WHERE id = snapshot_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness snapshot missing at commit';
    END IF;

    SELECT COUNT(*),
           COUNT(DISTINCT position),
           MIN(position),
           MAX(position),
           COUNT(*) FILTER (WHERE source_type = 'readiness_check'),
           COUNT(*) FILTER (WHERE source_type = 'payroll_source_row'),
           COUNT(*) FILTER (WHERE source_type = 'validation_issue'),
           COUNT(*) FILTER (WHERE source_type = 'validation_issue' AND evidence_status = 'blocking')
      INTO actual_item_count,
           actual_position_count,
           first_position,
           last_position,
           actual_check_count,
           actual_source_count,
           actual_validation_count,
           actual_blocker_count
      FROM workforce_payroll_readiness_snapshot_items
     WHERE payroll_readiness_snapshot_id = snapshot_id;

    IF actual_item_count <> snapshot.item_count
       OR actual_position_count <> snapshot.item_count
       OR first_position <> 1
       OR last_position <> snapshot.item_count
       OR actual_check_count <> 5
       OR actual_source_count <> snapshot.source_row_count
       OR actual_validation_count <> snapshot.validation_issue_count
       OR actual_blocker_count <> snapshot.blocker_count THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'payroll readiness item set incomplete';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER workforce_payroll_readiness_snapshots_lineage
BEFORE INSERT ON workforce_payroll_readiness_snapshots
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_readiness_snapshot_lineage_guard();

CREATE TRIGGER workforce_payroll_readiness_snapshot_items_lineage
BEFORE INSERT ON workforce_payroll_readiness_snapshot_items
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_readiness_item_lineage_guard();

CREATE CONSTRAINT TRIGGER workforce_payroll_readiness_snapshots_complete
AFTER INSERT ON workforce_payroll_readiness_snapshots
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_readiness_complete_guard();

CREATE CONSTRAINT TRIGGER workforce_payroll_readiness_snapshot_items_complete
AFTER INSERT ON workforce_payroll_readiness_snapshot_items
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_readiness_complete_guard();

CREATE TRIGGER workforce_payroll_readiness_snapshots_append_only
BEFORE UPDATE OR DELETE ON workforce_payroll_readiness_snapshots
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_readiness_prevent_mutation();

CREATE TRIGGER workforce_payroll_readiness_snapshot_items_append_only
BEFORE UPDATE OR DELETE ON workforce_payroll_readiness_snapshot_items
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_readiness_prevent_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS workforce_payroll_readiness_snapshot_items_append_only ON workforce_payroll_readiness_snapshot_items;
DROP TRIGGER IF EXISTS workforce_payroll_readiness_snapshots_append_only ON workforce_payroll_readiness_snapshots;
DROP TRIGGER IF EXISTS workforce_payroll_readiness_snapshot_items_complete ON workforce_payroll_readiness_snapshot_items;
DROP TRIGGER IF EXISTS workforce_payroll_readiness_snapshots_complete ON workforce_payroll_readiness_snapshots;
DROP TRIGGER IF EXISTS workforce_payroll_readiness_snapshot_items_lineage ON workforce_payroll_readiness_snapshot_items;
DROP TRIGGER IF EXISTS workforce_payroll_readiness_snapshots_lineage ON workforce_payroll_readiness_snapshots;
DROP FUNCTION IF EXISTS workforce_payroll_readiness_item_lineage_guard();
DROP FUNCTION IF EXISTS workforce_payroll_readiness_complete_guard();
DROP FUNCTION IF EXISTS workforce_payroll_readiness_snapshot_lineage_guard();
DROP FUNCTION IF EXISTS workforce_payroll_readiness_prevent_mutation();
SQL);
        }

        Schema::dropIfExists('workforce_payroll_readiness_snapshot_items');
        Schema::dropIfExists('workforce_payroll_readiness_snapshots');
    }
};
