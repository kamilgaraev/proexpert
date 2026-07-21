<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\Schema;

final class LegalWorkflowPostgresConstraints
{
    /** @return list<array{table: string, name: string, definition: string, type: string, deferrable: bool, deferred: bool}> */
    public static function definitions(): array
    {
        $definitions = [
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
            ['legal_workflow_decisions', 'legal_workflow_decisions_action_check', "CHECK (action IN ('approve', 'reject', 'return', 'reassign', 'cancel', 'expire') AND actor_type IN ('user', 'system') AND ((actor_type = 'user' AND actor_user_id IS NOT NULL) OR (actor_type = 'system' AND actor_user_id IS NULL)) AND request_hash ~ '^[a-f0-9]{64}$' AND document_content_hash ~ '^[a-f0-9]{64}$' AND ((action IN ('reject', 'return') AND NULLIF(BTRIM(comment), '') IS NOT NULL) OR (action IN ('reassign', 'cancel') AND NULLIF(BTRIM(reason), '') IS NOT NULL) OR action IN ('approve', 'expire')) AND ((action IN ('approve', 'reject', 'return', 'reassign') AND step_id IS NOT NULL) OR (action IN ('cancel', 'expire') AND step_id IS NULL)) AND ((action = 'approve' AND from_status = 'active' AND to_status = 'approved') OR (action = 'reject' AND from_status = 'active' AND to_status = 'rejected') OR (action = 'return' AND from_status = 'active' AND to_status = 'returned') OR (action = 'reassign' AND from_status = 'active' AND to_status = 'active') OR (action = 'cancel' AND from_status = 'in_progress' AND to_status = 'cancelled') OR (action = 'expire' AND from_status = 'in_progress' AND to_status = 'expired')) AND ((action = 'reassign' AND from_actor_type IN ('user', 'role', 'party', 'external') AND to_actor_type IN ('user', 'role', 'party', 'external') AND NULLIF(BTRIM(from_actor_reference), '') IS NOT NULL AND NULLIF(BTRIM(to_actor_reference), '') IS NOT NULL AND assignment_revision > 0) OR (action <> 'reassign' AND from_actor_type IS NULL AND from_actor_reference IS NULL AND from_due_at IS NULL AND to_actor_type IS NULL AND to_actor_reference IS NULL AND to_due_at IS NULL AND assignment_revision IS NULL AND previous_reassign_decision_id IS NULL)))"],
        ];

        return array_map(static function (array $descriptor): array {
            [$table, $name, $definition] = $descriptor;

            return [
                'table' => $table,
                'name' => $name,
                'definition' => $definition,
                'type' => str_starts_with($definition, 'FOREIGN KEY') ? 'f' : 'c',
                'deferrable' => str_contains($definition, 'DEFERRABLE'),
                'deferred' => str_contains($definition, 'INITIALLY DEFERRED'),
            ];
        }, $definitions);
    }

    /** @param array{table: string, name: string, definition: string, type: string, deferrable: bool, deferred: bool} $expected */
    public static function matches(object $actual, array $expected): bool
    {
        if ($expected['name'] === 'legal_workflow_template_steps_actor_check') {
            return $actual->table_name === $expected['table']
                && $actual->contype === $expected['type']
                && ! (bool) $actual->condeferrable
                && ! (bool) $actual->condeferred;
        }

        return $actual->table_name === $expected['table']
            && $actual->contype === $expected['type']
            && (bool) $actual->condeferrable === $expected['deferrable']
            && (bool) $actual->condeferred === $expected['deferred']
            && self::normalize($actual->definition) === self::normalize($expected['definition']);
    }

    private static function normalize(mixed $definition): string
    {
        $normalized = strtolower((string) $definition);
        $normalized = str_replace('not valid', '', $normalized);
        $normalized = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $normalized);
        $normalized = (string) preg_replace('/\b([a-z_]+)\s+between\s+(\d+)\s+and\s+(\d+)/', '$1 >= $2 and $1 <= $3', $normalized);
        $normalized = (string) preg_replace('/["\s()]+/', '', $normalized);
        $normalized = str_replace(['=anyarray[', ']'], ['in', ''], $normalized);

        return $normalized;
    }
}
