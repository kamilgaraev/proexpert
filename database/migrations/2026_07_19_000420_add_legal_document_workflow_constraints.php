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
        foreach ($this->constraints() as [$table, $name, $definition]) {
            if (DB::selectOne('SELECT 1 FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND conname = ?', [$name]) === null) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition} NOT VALID");
            }
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION legal_workflow_immutable_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'legal_workflow_delete_forbidden';
    END IF;
    IF TG_TABLE_NAME IN ('legal_workflow_templates', 'legal_workflow_template_steps', 'legal_workflow_decisions') THEN
        RAISE EXCEPTION 'legal_workflow_record_update_forbidden';
    END IF;
    IF TG_TABLE_NAME = 'legal_workflow_instances' AND
       (OLD.organization_id, OLD.document_id, OLD.document_version_id, OLD.document_content_hash,
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
    IF TG_TABLE_NAME = 'legal_workflow_instances'
       AND OLD.status IS DISTINCT FROM NEW.status
       AND NOT (OLD.status = 'in_progress' AND NEW.status IN ('approved', 'rejected', 'returned', 'cancelled', 'expired')) THEN
        RAISE EXCEPTION 'legal_workflow_instance_transition_forbidden';
    END IF;
    IF TG_TABLE_NAME = 'legal_workflow_steps' AND
       (OLD.instance_id, OLD.organization_id, OLD.step_key, OLD.label, OLD.sequence, OLD.parallel_group,
        OLD.required, OLD.policy_key, OLD.due_in_hours, OLD.deadline_at)
       IS DISTINCT FROM
       (NEW.instance_id, NEW.organization_id, NEW.step_key, NEW.label, NEW.sequence, NEW.parallel_group,
        NEW.required, NEW.policy_key, NEW.due_in_hours, NEW.deadline_at) THEN
        RAISE EXCEPTION 'legal_workflow_step_snapshot_update_forbidden';
    END IF;
    IF TG_TABLE_NAME = 'legal_workflow_steps'
       AND OLD.status IS DISTINCT FROM NEW.status
       AND NOT (
           (OLD.status = 'pending' AND NEW.status IN ('active', 'cancelled', 'expired'))
           OR (OLD.status = 'active' AND NEW.status IN ('approved', 'rejected', 'returned', 'cancelled', 'expired'))
       ) THEN
        RAISE EXCEPTION 'legal_workflow_step_transition_forbidden';
    END IF;
    IF TG_TABLE_NAME = 'legal_workflow_steps'
       AND (OLD.actor_type, OLD.actor_reference, OLD.due_at, OLD.assignment_revision, OLD.last_reassign_decision_id)
           IS DISTINCT FROM
           (NEW.actor_type, NEW.actor_reference, NEW.due_at, NEW.assignment_revision, NEW.last_reassign_decision_id) THEN
        IF OLD.status = 'pending' AND NEW.status = 'active'
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
CREATE TRIGGER legal_workflow_templates_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_templates FOR EACH ROW EXECUTE FUNCTION legal_workflow_immutable_guard();
DROP TRIGGER IF EXISTS legal_workflow_template_steps_immutable_guard ON legal_workflow_template_steps;
CREATE TRIGGER legal_workflow_template_steps_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_template_steps FOR EACH ROW EXECUTE FUNCTION legal_workflow_immutable_guard();
DROP TRIGGER IF EXISTS legal_workflow_instances_immutable_guard ON legal_workflow_instances;
CREATE TRIGGER legal_workflow_instances_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_instances FOR EACH ROW EXECUTE FUNCTION legal_workflow_immutable_guard();
DROP TRIGGER IF EXISTS legal_workflow_steps_immutable_guard ON legal_workflow_steps;
CREATE TRIGGER legal_workflow_steps_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_steps FOR EACH ROW EXECUTE FUNCTION legal_workflow_immutable_guard();
DROP TRIGGER IF EXISTS legal_workflow_decisions_immutable_guard ON legal_workflow_decisions;
CREATE TRIGGER legal_workflow_decisions_immutable_guard BEFORE UPDATE OR DELETE ON legal_workflow_decisions FOR EACH ROW EXECUTE FUNCTION legal_workflow_immutable_guard();

CREATE OR REPLACE FUNCTION legal_workflow_reassign_chain_guard() RETURNS trigger AS $$
DECLARE
    checked_decision legal_workflow_decisions%ROWTYPE;
    checked_step legal_workflow_steps%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'legal_workflow_decisions' THEN
        checked_decision := NEW;
        IF checked_decision.action <> 'reassign' THEN
            RETURN NEW;
        END IF;
        SELECT * INTO checked_step FROM legal_workflow_steps WHERE id = checked_decision.step_id;
        IF NOT FOUND OR checked_step.organization_id <> checked_decision.organization_id
           OR checked_step.instance_id <> checked_decision.instance_id
           OR NOT (
               checked_step.last_reassign_decision_id = checked_decision.id
               OR EXISTS (
                   SELECT 1 FROM legal_workflow_decisions next_decision
                   WHERE next_decision.previous_reassign_decision_id = checked_decision.id
                     AND next_decision.step_id = checked_decision.step_id
                     AND next_decision.organization_id = checked_decision.organization_id
                     AND next_decision.assignment_revision = checked_decision.assignment_revision + 1
               )
           )
           OR (
               checked_decision.previous_reassign_decision_id IS NULL
               AND checked_decision.assignment_revision <> 1
           )
           OR (
               checked_decision.previous_reassign_decision_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM legal_workflow_decisions previous_decision
                   WHERE previous_decision.id = checked_decision.previous_reassign_decision_id
                     AND previous_decision.step_id = checked_decision.step_id
                     AND previous_decision.organization_id = checked_decision.organization_id
                     AND previous_decision.assignment_revision + 1 = checked_decision.assignment_revision
                     AND previous_decision.to_actor_type = checked_decision.from_actor_type
                     AND previous_decision.to_actor_reference = checked_decision.from_actor_reference
                     AND previous_decision.to_due_at IS NOT DISTINCT FROM checked_decision.from_due_at
               )
           ) THEN
            RAISE EXCEPTION 'legal_workflow_reassign_chain_invalid';
        END IF;
        RETURN NEW;
    END IF;

    SELECT * INTO checked_decision FROM legal_workflow_decisions WHERE id = NEW.last_reassign_decision_id;
    IF NEW.last_reassign_decision_id IS NOT NULL AND (
        NOT FOUND
        OR checked_decision.action <> 'reassign'
        OR checked_decision.step_id <> NEW.id
        OR checked_decision.organization_id <> NEW.organization_id
        OR checked_decision.assignment_revision <> NEW.assignment_revision
        OR checked_decision.to_actor_type <> NEW.actor_type
        OR checked_decision.to_actor_reference <> NEW.actor_reference
        OR checked_decision.to_due_at IS DISTINCT FROM NEW.due_at
    ) THEN
        RAISE EXCEPTION 'legal_workflow_reassign_projection_invalid';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS legal_workflow_decisions_reassign_chain_guard ON legal_workflow_decisions;
CREATE CONSTRAINT TRIGGER legal_workflow_decisions_reassign_chain_guard
AFTER INSERT ON legal_workflow_decisions DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION legal_workflow_reassign_chain_guard();
DROP TRIGGER IF EXISTS legal_workflow_steps_reassign_projection_guard ON legal_workflow_steps;
CREATE CONSTRAINT TRIGGER legal_workflow_steps_reassign_projection_guard
AFTER UPDATE OF last_reassign_decision_id, assignment_revision, actor_type, actor_reference, due_at ON legal_workflow_steps
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION legal_workflow_reassign_chain_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('legal_workflow_migrations_are_forward_only');
    }

    /** @return list<array{string, string, string}> */
    private function constraints(): array
    {
        return [
            ['legal_workflow_template_heads', 'legal_workflow_heads_template_fk', 'FOREIGN KEY (template_id, organization_id, code) REFERENCES legal_workflow_templates (id, organization_id, code) ON DELETE RESTRICT'],
            ['legal_workflow_template_steps', 'legal_workflow_template_steps_template_fk', 'FOREIGN KEY (template_id, organization_id) REFERENCES legal_workflow_templates (id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_instances', 'legal_workflow_instances_document_fk', 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_instances', 'legal_workflow_instances_version_fk', 'FOREIGN KEY (document_version_id, document_id, organization_id, document_content_hash) REFERENCES legal_archive_document_versions (id, document_id, organization_id, content_hash) ON DELETE RESTRICT'],
            ['legal_workflow_instances', 'legal_workflow_instances_template_fk', 'FOREIGN KEY (template_id, organization_id, template_version, template_definition_hash) REFERENCES legal_workflow_templates (id, organization_id, version, definition_hash) ON DELETE RESTRICT'],
            ['legal_workflow_steps', 'legal_workflow_steps_instance_fk', 'FOREIGN KEY (instance_id, organization_id) REFERENCES legal_workflow_instances (id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_decisions', 'legal_workflow_decisions_instance_fk', 'FOREIGN KEY (instance_id, document_id, document_version_id, organization_id, document_content_hash) REFERENCES legal_workflow_instances (id, document_id, document_version_id, organization_id, document_content_hash) ON DELETE RESTRICT'],
            ['legal_workflow_decisions', 'legal_workflow_decisions_step_fk', 'FOREIGN KEY (step_id, instance_id, organization_id) REFERENCES legal_workflow_steps (id, instance_id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_steps', 'legal_workflow_steps_last_reassign_fk', 'FOREIGN KEY (last_reassign_decision_id, id, organization_id) REFERENCES legal_workflow_decisions (id, step_id, organization_id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED'],
            ['legal_workflow_decisions', 'legal_workflow_decisions_previous_reassign_fk', 'FOREIGN KEY (previous_reassign_decision_id, step_id, organization_id) REFERENCES legal_workflow_decisions (id, step_id, organization_id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED'],
            ['legal_workflow_templates', 'legal_workflow_templates_hash_check', "CHECK (definition_hash ~ '^[a-f0-9]{64}$')"],
            ['legal_workflow_template_steps', 'legal_workflow_template_steps_actor_check', "CHECK (actor_type IN ('user', 'role', 'party', 'external') AND actor_reference <> '' AND sequence > 0 AND (due_in_hours IS NULL OR due_in_hours BETWEEN 1 AND 8760))"],
            ['legal_workflow_instances', 'legal_workflow_instances_status_check', "CHECK (status IN ('in_progress', 'approved', 'rejected', 'returned', 'cancelled', 'expired') AND template_definition_hash ~ '^[a-f0-9]{64}$' AND snapshot_hash ~ '^[a-f0-9]{64}$' AND client_request_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$' AND document_content_hash ~ '^[a-f0-9]{64}$' AND reconciliation_attempts <= 100 AND ((reconciliation_required_at IS NULL AND reconciliation_reason IS NULL) OR (reconciliation_required_at IS NOT NULL AND NULLIF(BTRIM(reconciliation_reason), '') IS NOT NULL)))"],
            ['legal_workflow_steps', 'legal_workflow_steps_status_check', "CHECK (status IN ('pending', 'active', 'approved', 'rejected', 'returned', 'cancelled', 'expired') AND actor_type IN ('user', 'role', 'party', 'external') AND actor_reference <> '' AND sequence > 0 AND assignment_revision >= 0 AND ((assignment_revision = 0 AND last_reassign_decision_id IS NULL) OR (assignment_revision > 0 AND last_reassign_decision_id IS NOT NULL)) AND (due_in_hours IS NULL OR due_in_hours BETWEEN 1 AND 8760))"],
            ['legal_workflow_decisions', 'legal_workflow_decisions_action_check', "CHECK (action IN ('approve', 'reject', 'return', 'reassign', 'cancel', 'expire') AND actor_type IN ('user', 'system') AND ((actor_type = 'user' AND actor_user_id IS NOT NULL) OR (actor_type = 'system' AND actor_user_id IS NULL)) AND request_hash ~ '^[a-f0-9]{64}$' AND document_content_hash ~ '^[a-f0-9]{64}$' AND ((action IN ('reject', 'return', 'reassign', 'cancel') AND (NULLIF(BTRIM(comment), '') IS NOT NULL OR NULLIF(BTRIM(reason), '') IS NOT NULL)) OR action IN ('approve', 'expire')) AND ((action = 'reassign' AND from_actor_type IN ('user', 'role', 'party', 'external') AND to_actor_type IN ('user', 'role', 'party', 'external') AND NULLIF(BTRIM(from_actor_reference), '') IS NOT NULL AND NULLIF(BTRIM(to_actor_reference), '') IS NOT NULL AND to_due_at IS NOT NULL AND assignment_revision > 0) OR (action <> 'reassign' AND from_actor_type IS NULL AND from_actor_reference IS NULL AND from_due_at IS NULL AND to_actor_type IS NULL AND to_actor_reference IS NULL AND to_due_at IS NULL AND assignment_revision IS NULL AND previous_reassign_decision_id IS NULL)))"],
        ];
    }
};
