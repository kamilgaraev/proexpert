<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->indexes() as $name => $sql) {
            $invalid = DB::selectOne(
                'SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ? AND i.indisvalid = false',
                [$name],
            );
            if ($invalid !== null) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_workflow_migrations_are_forward_only');
    }

    /** @return array<string, string> */
    private function indexes(): array
    {
        return [
            'legal_workflow_templates_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_templates_ownership_unique ON legal_workflow_templates (id, organization_id, code)',
            'legal_workflow_templates_tenant_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_templates_tenant_unique ON legal_workflow_templates (id, organization_id)',
            'legal_workflow_templates_exact_version_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_templates_exact_version_unique ON legal_workflow_templates (id, organization_id, version, definition_hash)',
            'legal_workflow_template_steps_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_template_steps_ownership_unique ON legal_workflow_template_steps (id, template_id, organization_id)',
            'legal_archive_versions_workflow_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_versions_workflow_ownership_unique ON legal_archive_document_versions (id, document_id, organization_id, content_hash)',
            'legal_workflow_instances_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_instances_ownership_unique ON legal_workflow_instances (id, document_id, document_version_id, organization_id, document_content_hash)',
            'legal_workflow_instances_tenant_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_instances_tenant_unique ON legal_workflow_instances (id, organization_id)',
            'legal_workflow_steps_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_steps_ownership_unique ON legal_workflow_steps (id, instance_id, organization_id)',
            'legal_workflow_decisions_reassign_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_decisions_reassign_ownership_unique ON legal_workflow_decisions (id, step_id, organization_id)',
            'legal_workflow_instances_active_unique' => "CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_instances_active_unique ON legal_workflow_instances (organization_id, document_id) WHERE status = 'in_progress'",
            'legal_workflow_steps_actor_queue_idx' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_steps_actor_queue_idx ON legal_workflow_steps (organization_id, actor_type, actor_reference, due_at) WHERE status = 'active'",
            'legal_workflow_instances_reconcile_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_instances_reconcile_idx ON legal_workflow_instances (organization_id, reconciliation_required_at) WHERE reconciliation_required_at IS NOT NULL',
            'legal_workflow_decisions_terminal_unique' => "CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_decisions_terminal_unique ON legal_workflow_decisions (step_id) WHERE step_id IS NOT NULL AND action IN ('approve', 'reject', 'return')",
            'legal_workflow_decisions_reassign_revision_unique' => "CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_decisions_reassign_revision_unique ON legal_workflow_decisions (step_id, assignment_revision) WHERE action = 'reassign'",
            'legal_workflow_decisions_reassign_previous_unique' => "CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_decisions_reassign_previous_unique ON legal_workflow_decisions (previous_reassign_decision_id) WHERE action = 'reassign' AND previous_reassign_decision_id IS NOT NULL",
        ];
    }
};
