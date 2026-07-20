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
        foreach ($this->indexes() as $name => $expected) {
            $actual = $this->indexDescriptor($name);
            if ($actual !== null && ! $this->sameDescriptor($actual, $expected)) {
                if ((bool) $actual->indisvalid && (bool) $actual->indisready && (bool) $actual->indislive) {
                    throw new RuntimeException("legal_workflow_index_descriptor_mismatch:{$name}");
                }
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                $actual = null;
            }
            if ($actual === null) {
                DB::statement($expected['sql']);
            }
            $verified = $this->indexDescriptor($name);
            if ($verified === null || ! $this->sameDescriptor($verified, $expected)) {
                throw new RuntimeException("legal_workflow_index_descriptor_mismatch:{$name}");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_workflow_migrations_are_forward_only');
    }

    /** @return array<string, array{table: string, unique: bool, method: string, keys: list<string>, include: list<string>, nulls_not_distinct: bool, predicate: ?string, sql: string}> */
    private function indexes(): array
    {
        return [
            'legal_workflow_templates_ownership_unique' => $this->descriptor('legal_workflow_templates', true, ['id', 'organization_id', 'code'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_templates_ownership_unique ON legal_workflow_templates (id, organization_id, code)'),
            'legal_workflow_templates_tenant_unique' => $this->descriptor('legal_workflow_templates', true, ['id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_templates_tenant_unique ON legal_workflow_templates (id, organization_id)'),
            'legal_workflow_templates_exact_version_unique' => $this->descriptor('legal_workflow_templates', true, ['id', 'organization_id', 'version', 'definition_hash'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_templates_exact_version_unique ON legal_workflow_templates (id, organization_id, version, definition_hash)'),
            'legal_workflow_template_steps_ownership_unique' => $this->descriptor('legal_workflow_template_steps', true, ['id', 'template_id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_template_steps_ownership_unique ON legal_workflow_template_steps (id, template_id, organization_id)'),
            'legal_archive_versions_workflow_ownership_unique' => $this->descriptor('legal_archive_document_versions', true, ['id', 'document_id', 'organization_id', 'content_hash'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_archive_versions_workflow_ownership_unique ON legal_archive_document_versions (id, document_id, organization_id, content_hash)'),
            'legal_workflow_instances_ownership_unique' => $this->descriptor('legal_workflow_instances', true, ['id', 'document_id', 'document_version_id', 'organization_id', 'document_content_hash'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_instances_ownership_unique ON legal_workflow_instances (id, document_id, document_version_id, organization_id, document_content_hash)'),
            'legal_workflow_instances_tenant_unique' => $this->descriptor('legal_workflow_instances', true, ['id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_instances_tenant_unique ON legal_workflow_instances (id, organization_id)'),
            'legal_workflow_steps_ownership_unique' => $this->descriptor('legal_workflow_steps', true, ['id', 'instance_id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_steps_ownership_unique ON legal_workflow_steps (id, instance_id, organization_id)'),
            'legal_workflow_decisions_reassign_ownership_unique' => $this->descriptor('legal_workflow_decisions', true, ['id', 'step_id', 'organization_id'], null, 'CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_decisions_reassign_ownership_unique ON legal_workflow_decisions (id, step_id, organization_id)'),
            'legal_workflow_instances_active_unique' => $this->descriptor('legal_workflow_instances', true, ['organization_id', 'document_id'], "status = 'in_progress'", "CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_instances_active_unique ON legal_workflow_instances (organization_id, document_id) WHERE status = 'in_progress'"),
            'legal_workflow_steps_actor_queue_idx' => $this->descriptor('legal_workflow_steps', false, ['organization_id', 'actor_type', 'actor_reference', 'due_at'], "status = 'active'", "CREATE INDEX CONCURRENTLY legal_workflow_steps_actor_queue_idx ON legal_workflow_steps (organization_id, actor_type, actor_reference, due_at) WHERE status = 'active'"),
            'legal_workflow_instances_reconcile_idx' => $this->descriptor('legal_workflow_instances', false, ['organization_id', 'reconciliation_required_at'], 'reconciliation_required_at IS NOT NULL', 'CREATE INDEX CONCURRENTLY legal_workflow_instances_reconcile_idx ON legal_workflow_instances (organization_id, reconciliation_required_at) WHERE reconciliation_required_at IS NOT NULL'),
            'legal_workflow_decisions_terminal_unique' => $this->descriptor('legal_workflow_decisions', true, ['step_id'], "step_id IS NOT NULL AND action IN ('approve', 'reject', 'return')", "CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_decisions_terminal_unique ON legal_workflow_decisions (step_id) WHERE step_id IS NOT NULL AND action IN ('approve', 'reject', 'return')"),
            'legal_workflow_decisions_reassign_revision_unique' => $this->descriptor('legal_workflow_decisions', true, ['step_id', 'assignment_revision'], "action = 'reassign'", "CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_decisions_reassign_revision_unique ON legal_workflow_decisions (step_id, assignment_revision) WHERE action = 'reassign'"),
            'legal_workflow_decisions_reassign_previous_unique' => $this->descriptor('legal_workflow_decisions', true, ['previous_reassign_decision_id'], "action = 'reassign' AND previous_reassign_decision_id IS NOT NULL", "CREATE UNIQUE INDEX CONCURRENTLY legal_workflow_decisions_reassign_previous_unique ON legal_workflow_decisions (previous_reassign_decision_id) WHERE action = 'reassign' AND previous_reassign_decision_id IS NOT NULL"),
        ];
    }

    /** @param list<string> $keys @return array{table: string, unique: bool, method: string, keys: list<string>, include: list<string>, nulls_not_distinct: bool, predicate: ?string, sql: string} */
    private function descriptor(string $table, bool $unique, array $keys, ?string $predicate, string $sql): array
    {
        return [
            'table' => $table,
            'unique' => $unique,
            'method' => 'btree',
            'keys' => $keys,
            'include' => [],
            'nulls_not_distinct' => false,
            'predicate' => $predicate,
            'sql' => $sql,
        ];
    }

    /** @param array{table: string, unique: bool, method: string, keys: list<string>, include: list<string>, nulls_not_distinct: bool, predicate: ?string, sql: string} $expected */
    private function sameDescriptor(object $actual, array $expected): bool
    {
        return $actual->table_name === $expected['table']
            && (bool) $actual->indisunique === $expected['unique']
            && (bool) $actual->indisvalid
            && (bool) $actual->indisready
            && (bool) $actual->indislive
            && $actual->access_method === $expected['method']
            && (bool) $actual->indnullsnotdistinct === $expected['nulls_not_distinct']
            && (int) $actual->indnkeyatts === count($expected['keys'])
            && (int) $actual->indnatts === count($expected['keys']) + count($expected['include'])
            && $this->definitions($actual->key_definitions) === $expected['keys']
            && $this->definitions($actual->include_definitions) === $expected['include']
            && $this->normalizePredicate($actual->predicate) === $this->normalizePredicate($expected['predicate']);
    }

    private function indexDescriptor(string $name): ?object
    {
        return DB::selectOne(
            <<<'SQL'
SELECT table_class.relname AS table_name,
       access_method.amname AS access_method,
       i.indisunique::integer AS indisunique,
       i.indisvalid::integer AS indisvalid,
       i.indisready::integer AS indisready,
       i.indislive::integer AS indislive,
       i.indnkeyatts, i.indnatts,
       i.indnullsnotdistinct::integer AS indnullsnotdistinct,
       to_json(ARRAY(
           SELECT pg_get_indexdef(i.indexrelid, position, true)
           FROM generate_series(1, i.indnkeyatts) AS position
           ORDER BY position
       )) AS key_definitions,
       to_json(ARRAY(
           SELECT pg_get_indexdef(i.indexrelid, position, true)
           FROM generate_series(i.indnkeyatts + 1, i.indnatts) AS position
           ORDER BY position
       )) AS include_definitions,
       pg_get_expr(i.indpred, i.indrelid) AS predicate
FROM pg_index i
JOIN pg_class index_class ON index_class.oid = i.indexrelid
JOIN pg_namespace namespace ON namespace.oid = index_class.relnamespace
JOIN pg_class table_class ON table_class.oid = i.indrelid
JOIN pg_am access_method ON access_method.oid = index_class.relam
WHERE namespace.nspname = current_schema() AND index_class.relname = ?
SQL,
            [$name],
        );
    }

    /** @return list<string> */
    private function definitions(mixed $definitions): array
    {
        if (is_array($definitions)) {
            return array_values(array_map('strval', $definitions));
        }
        if (! is_string($definitions)) {
            return [];
        }
        $decoded = json_decode($definitions, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function normalizePredicate(mixed $predicate): ?string
    {
        if ($predicate === null || trim((string) $predicate) === '') {
            return null;
        }

        $normalized = strtolower((string) $predicate);
        $normalized = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $normalized);
        $normalized = (string) preg_replace('/["()\s]+/', '', $normalized);
        $normalized = str_replace(['=anyarray[', ']'], ['in', ''], $normalized);

        return $normalized;
    }
};
