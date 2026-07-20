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
        OLD.template_id, OLD.template_version, OLD.template_snapshot, OLD.snapshot_hash, OLD.request_hash,
        OLD.idempotency_key, OLD.submitted_by_user_id, OLD.submitted_at)
       IS DISTINCT FROM
       (NEW.organization_id, NEW.document_id, NEW.document_version_id, NEW.document_content_hash,
        NEW.template_id, NEW.template_version, NEW.template_snapshot, NEW.snapshot_hash, NEW.request_hash,
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
       AND (OLD.actor_type, OLD.actor_reference) IS DISTINCT FROM (NEW.actor_type, NEW.actor_reference)
       AND NOT (OLD.status = 'active' AND NEW.status = 'active') THEN
        RAISE EXCEPTION 'legal_workflow_step_reassignment_forbidden';
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
SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['legal_workflow_decisions', 'legal_workflow_steps', 'legal_workflow_instances', 'legal_workflow_template_steps', 'legal_workflow_templates'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_immutable_guard ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS legal_workflow_immutable_guard()');
        foreach (array_reverse($this->constraints()) as [$table, $name]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }

    /** @return list<array{string, string, string}> */
    private function constraints(): array
    {
        return [
            ['legal_workflow_template_heads', 'legal_workflow_heads_template_fk', 'FOREIGN KEY (template_id, organization_id, code) REFERENCES legal_workflow_templates (id, organization_id, code) ON DELETE RESTRICT'],
            ['legal_workflow_template_steps', 'legal_workflow_template_steps_template_fk', 'FOREIGN KEY (template_id, organization_id) REFERENCES legal_workflow_templates (id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_instances', 'legal_workflow_instances_document_fk', 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_instances', 'legal_workflow_instances_version_fk', 'FOREIGN KEY (document_version_id, document_id, organization_id, document_content_hash) REFERENCES legal_archive_document_versions (id, document_id, organization_id, content_hash) ON DELETE RESTRICT'],
            ['legal_workflow_instances', 'legal_workflow_instances_template_fk', 'FOREIGN KEY (template_id, organization_id) REFERENCES legal_workflow_templates (id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_steps', 'legal_workflow_steps_instance_fk', 'FOREIGN KEY (instance_id, organization_id) REFERENCES legal_workflow_instances (id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_decisions', 'legal_workflow_decisions_instance_fk', 'FOREIGN KEY (instance_id, document_id, document_version_id, organization_id, document_content_hash) REFERENCES legal_workflow_instances (id, document_id, document_version_id, organization_id, document_content_hash) ON DELETE RESTRICT'],
            ['legal_workflow_decisions', 'legal_workflow_decisions_step_fk', 'FOREIGN KEY (step_id, instance_id, organization_id) REFERENCES legal_workflow_steps (id, instance_id, organization_id) ON DELETE RESTRICT'],
            ['legal_workflow_templates', 'legal_workflow_templates_hash_check', "CHECK (definition_hash ~ '^[a-f0-9]{64}$')"],
            ['legal_workflow_template_steps', 'legal_workflow_template_steps_actor_check', "CHECK (actor_type IN ('user', 'role', 'party', 'external') AND actor_reference <> '' AND sequence > 0 AND (due_in_hours IS NULL OR due_in_hours BETWEEN 1 AND 8760))"],
            ['legal_workflow_instances', 'legal_workflow_instances_status_check', "CHECK (status IN ('in_progress', 'approved', 'rejected', 'returned', 'cancelled', 'expired') AND snapshot_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$' AND document_content_hash ~ '^[a-f0-9]{64}$' AND reconciliation_attempts <= 100 AND ((reconciliation_required_at IS NULL AND reconciliation_reason IS NULL) OR (reconciliation_required_at IS NOT NULL AND NULLIF(BTRIM(reconciliation_reason), '') IS NOT NULL)))"],
            ['legal_workflow_steps', 'legal_workflow_steps_status_check', "CHECK (status IN ('pending', 'active', 'approved', 'rejected', 'returned', 'cancelled', 'expired') AND actor_type IN ('user', 'role', 'party', 'external') AND actor_reference <> '' AND sequence > 0 AND (due_in_hours IS NULL OR due_in_hours BETWEEN 1 AND 8760))"],
            ['legal_workflow_decisions', 'legal_workflow_decisions_action_check', "CHECK (action IN ('approve', 'reject', 'return', 'reassign', 'cancel', 'expire') AND actor_type IN ('user', 'system') AND ((actor_type = 'user' AND actor_user_id IS NOT NULL) OR (actor_type = 'system' AND actor_user_id IS NULL)) AND request_hash ~ '^[a-f0-9]{64}$' AND document_content_hash ~ '^[a-f0-9]{64}$' AND ((action IN ('reject', 'return', 'reassign', 'cancel') AND (NULLIF(BTRIM(comment), '') IS NOT NULL OR NULLIF(BTRIM(reason), '') IS NOT NULL)) OR action IN ('approve', 'expire')))"],
        ];
    }
};
