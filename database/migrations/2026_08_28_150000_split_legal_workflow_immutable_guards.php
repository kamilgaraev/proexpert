<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION legal_workflow_record_immutable_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'legal_workflow_delete_forbidden';
    END IF;
    RAISE EXCEPTION 'legal_workflow_record_update_forbidden';
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION legal_workflow_instance_immutable_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'legal_workflow_delete_forbidden';
    END IF;
    IF (OLD.organization_id, OLD.document_id, OLD.document_version_id, OLD.document_content_hash,
        OLD.template_id, OLD.template_version, OLD.template_definition_hash, OLD.template_snapshot, OLD.snapshot_hash,
        OLD.client_request_hash, OLD.request_hash,
        OLD.idempotency_key, OLD.submitted_by_user_id, OLD.submitted_at)
       IS DISTINCT FROM
       (NEW.organization_id, NEW.document_id, NEW.document_version_id, NEW.document_content_hash,
        NEW.template_id, NEW.template_version, NEW.template_definition_hash, NEW.template_snapshot, NEW.snapshot_hash,
        NEW.client_request_hash, NEW.request_hash,
        NEW.idempotency_key, NEW.submitted_by_user_id, NEW.submitted_at) THEN
        RAISE EXCEPTION 'legal_workflow_snapshot_update_forbidden';
    END IF;
    IF OLD.status IS DISTINCT FROM NEW.status
       AND current_setting('app.legal_workflow_recovery', true) IS DISTINCT FROM 'service'
       AND NOT (OLD.status = 'in_progress' AND NEW.status IN ('approved', 'rejected', 'returned', 'cancelled', 'expired')) THEN
        RAISE EXCEPTION 'legal_workflow_instance_transition_forbidden';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION legal_workflow_step_immutable_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'legal_workflow_delete_forbidden';
    END IF;
    IF (OLD.instance_id, OLD.organization_id, OLD.step_key, OLD.label, OLD.sequence, OLD.parallel_group,
        OLD.required, OLD.policy_key, OLD.due_in_hours, OLD.deadline_at)
       IS DISTINCT FROM
       (NEW.instance_id, NEW.organization_id, NEW.step_key, NEW.label, NEW.sequence, NEW.parallel_group,
        NEW.required, NEW.policy_key, NEW.due_in_hours, NEW.deadline_at) THEN
        RAISE EXCEPTION 'legal_workflow_step_snapshot_update_forbidden';
    END IF;
    IF OLD.status IS DISTINCT FROM NEW.status
       AND current_setting('app.legal_workflow_recovery', true) IS DISTINCT FROM 'service'
       AND NOT (
           (OLD.status = 'pending' AND NEW.status IN ('active', 'cancelled', 'expired'))
           OR (OLD.status = 'active' AND NEW.status IN ('approved', 'rejected', 'returned', 'cancelled', 'expired'))
       ) THEN
        RAISE EXCEPTION 'legal_workflow_step_transition_forbidden';
    END IF;
    IF (OLD.actor_type, OLD.actor_reference, OLD.due_at, OLD.assignment_revision, OLD.last_reassign_decision_id)
       IS DISTINCT FROM
       (NEW.actor_type, NEW.actor_reference, NEW.due_at, NEW.assignment_revision, NEW.last_reassign_decision_id) THEN
        IF current_setting('app.legal_workflow_recovery', true) = 'service' THEN
            NULL;
        ELSIF OLD.status = 'pending' AND NEW.status = 'active'
           AND (OLD.actor_type, OLD.actor_reference, OLD.assignment_revision, OLD.last_reassign_decision_id)
               IS NOT DISTINCT FROM
               (NEW.actor_type, NEW.actor_reference, NEW.assignment_revision, NEW.last_reassign_decision_id)
           AND NEW.due_at IS NOT DISTINCT FROM COALESCE(OLD.deadline_at, NEW.activated_at + make_interval(hours => OLD.due_in_hours::integer)) THEN
            NULL;
        ELSIF OLD.status = 'active' AND NEW.status = 'active'
           AND NULLIF(current_setting('app.legal_workflow_reassign_decision_id', true), '') IS NOT NULL
           AND EXISTS (
               SELECT 1 FROM legal_workflow_decisions d
               WHERE d.id = current_setting('app.legal_workflow_reassign_decision_id', true)::bigint
                 AND d.action = 'reassign'
                 AND d.step_id = NEW.id
                 AND d.instance_id = NEW.instance_id
                 AND d.organization_id = NEW.organization_id
                 AND d.from_actor_type = OLD.actor_type
                 AND d.from_actor_reference = OLD.actor_reference
                 AND d.from_due_at IS NOT DISTINCT FROM OLD.due_at
                 AND d.to_actor_type = NEW.actor_type
                 AND d.to_actor_reference = NEW.actor_reference
                 AND d.to_due_at IS NOT DISTINCT FROM NEW.due_at
                 AND d.assignment_revision = OLD.assignment_revision + 1
                 AND d.assignment_revision = NEW.assignment_revision
                 AND d.previous_reassign_decision_id IS NOT DISTINCT FROM OLD.last_reassign_decision_id
                 AND d.id = NEW.last_reassign_decision_id
           ) THEN
            NULL;
        ELSE
            RAISE EXCEPTION 'legal_workflow_step_assignment_update_forbidden';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS legal_workflow_templates_immutable_guard ON legal_workflow_templates;
CREATE TRIGGER legal_workflow_templates_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_templates FOR EACH ROW EXECUTE FUNCTION legal_workflow_record_immutable_guard();
DROP TRIGGER IF EXISTS legal_workflow_template_steps_immutable_guard ON legal_workflow_template_steps;
CREATE TRIGGER legal_workflow_template_steps_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_template_steps FOR EACH ROW EXECUTE FUNCTION legal_workflow_record_immutable_guard();
DROP TRIGGER IF EXISTS legal_workflow_instances_immutable_guard ON legal_workflow_instances;
CREATE TRIGGER legal_workflow_instances_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_instances FOR EACH ROW EXECUTE FUNCTION legal_workflow_instance_immutable_guard();
DROP TRIGGER IF EXISTS legal_workflow_steps_immutable_guard ON legal_workflow_steps;
CREATE TRIGGER legal_workflow_steps_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_steps FOR EACH ROW EXECUTE FUNCTION legal_workflow_step_immutable_guard();
DROP TRIGGER IF EXISTS legal_workflow_decisions_immutable_guard ON legal_workflow_decisions;
CREATE TRIGGER legal_workflow_decisions_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_decisions FOR EACH ROW EXECUTE FUNCTION legal_workflow_record_immutable_guard();

DROP FUNCTION IF EXISTS legal_workflow_immutable_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('legal_workflow_migrations_are_forward_only');
    }
};
