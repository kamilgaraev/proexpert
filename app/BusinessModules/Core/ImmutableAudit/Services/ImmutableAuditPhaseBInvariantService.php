<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions;
use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class ImmutableAuditPhaseBInvariantService
{
    /** @param null|Closure(ConnectionInterface):array<string,bool> $snapshotLoader */
    public function __construct(
        private readonly ?Closure $snapshotLoader = null,
    ) {}

    public function failureReason(ConnectionInterface $connection): ?string
    {
        if ($this->snapshotLoader !== null) {
            return $this->snapshotFailureReason(($this->snapshotLoader)($connection));
        }
        if ($connection->getDriverName() !== 'pgsql') {
            return 'immutable_audit_sequence_invalid';
        }

        $current = $this->currentDescriptors($connection);
        foreach ($this->expectedDescriptors() as $invariant => $expected) {
            if (! isset($current[$invariant]) || ! hash_equals($this->descriptorHash($expected), $this->descriptorHash($current[$invariant]))) {
                return $this->failureReasons()[$invariant];
            }
        }

        return null;
    }

    public function installCanonicalCore(ConnectionInterface $connection): void
    {
        $connection->statement('DROP TABLE IF EXISTS immutable_audit_invariant_baselines');
        $connection->statement(ImmutableAuditInvariantDefinitions::SEQUENCE_CREATE_SQL);
        $connection->statement(ImmutableAuditInvariantDefinitions::SEQUENCE_ALTER_SQL);
        $connection->unprepared(ImmutableAuditInvariantDefinitions::canonicalCoreSql());
        $this->assertCanonicalCore($connection);
    }

    public function preparePhaseBIndexes(ConnectionInterface $connection): void
    {
        $this->ensurePhaseBIndex($connection, 'immutable_audit_source_event_aggregate_unique', ['organization_id', 'domain', 'subject_type', 'subject_id', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL');
        $this->ensurePhaseBIndex($connection, 'immutable_audit_source_event_legacy_unique', ['organization_id', 'domain', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)');
    }

    public function assertPermanentInvariants(ConnectionInterface $connection): void
    {
        $reason = $this->failureReason($connection);
        if ($reason !== null) {
            throw new RuntimeException('immutable_audit_invariant_verification_failed:'.$reason);
        }
    }

    /** @param list<string> $columns */
    public function indexIsValid(ConnectionInterface $connection, string $name, array $columns, string $predicate): bool
    {
        $index = $this->index($connection, $name);

        return $index !== null
            && $this->postgresBoolean($index->indisvalid)
            && $this->postgresBoolean($index->indisready)
            && $this->postgresBoolean($index->indisunique)
            && $this->postgresArray($index->columns) === $columns
            && $this->normalizeSql((string) $index->predicate) === $this->normalizeSql($predicate)
            && $this->normalizeIndexDefinition((string) $index->definition) === $this->expectedIndexDefinition($name, $columns, $predicate);
    }

    public function indexExists(ConnectionInterface $connection, string $name): bool
    {
        return $this->index($connection, $name) !== null;
    }

    /** @return array<string,array<string,mixed>> */
    private function expectedDescriptors(): array
    {
        return ImmutableAuditInvariantDefinitions::expectedFunctions()
            + ImmutableAuditInvariantDefinitions::expectedTriggers()
            + ['sequence' => ImmutableAuditInvariantDefinitions::expectedSequence()]
            + [
                'aggregate_index' => $this->expectedIndexDescriptor('immutable_audit_source_event_aggregate_unique', ['organization_id', 'domain', 'subject_type', 'subject_id', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL'),
                'legacy_index' => $this->expectedIndexDescriptor('immutable_audit_source_event_legacy_unique', ['organization_id', 'domain', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)'),
            ];
    }

    /** @return array<string,array<string,mixed>> */
    private function currentDescriptors(ConnectionInterface $connection): array
    {
        $descriptors = [];
        foreach (['allocator' => 'immutable_audit_allocate_sequence', 'writer_guard_function' => 'immutable_audit_writer_guard', 'append_only_function' => 'immutable_audit_prevent_mutation', 'sequence_sync_function' => 'immutable_audit_sync_sequence_after_insert'] as $key => $function) {
            $row = $this->functionCatalog($connection, $function);
            if ($row !== null) {
                $descriptors[$key] = $this->functionDescriptor($row);
            }
        }
        foreach (['writer_guard_trigger' => 'immutable_audit_writer_guard', 'append_only_trigger' => 'immutable_audit_events_append_only', 'sequence_sync_trigger' => 'immutable_audit_sequence_sync'] as $key => $trigger) {
            $row = $this->triggerCatalog($connection, $trigger);
            if ($row !== null) {
                $descriptors[$key] = $this->triggerDescriptor($row);
            }
        }
        $sequence = $this->sequenceCatalog($connection);
        if ($sequence !== null) {
            $descriptors['sequence'] = $this->sequenceDescriptor($sequence);
        }
        foreach (['aggregate_index' => 'immutable_audit_source_event_aggregate_unique', 'legacy_index' => 'immutable_audit_source_event_legacy_unique'] as $key => $name) {
            $index = $this->index($connection, $name);
            if ($index !== null) {
                $descriptors[$key] = $this->indexDescriptor($index);
            }
        }

        return $descriptors;
    }

    private function assertCanonicalCore(ConnectionInterface $connection): void
    {
        $current = $this->currentDescriptors($connection);
        $expected = $this->expectedDescriptors();
        foreach (array_merge(array_keys(ImmutableAuditInvariantDefinitions::expectedFunctions()), array_keys(ImmutableAuditInvariantDefinitions::expectedTriggers()), ['sequence']) as $invariant) {
            if (! isset($current[$invariant]) || ! hash_equals($this->descriptorHash($expected[$invariant]), $this->descriptorHash($current[$invariant]))) {
                throw new RuntimeException('immutable_audit_canonical_invariant_invalid:'.$invariant);
            }
        }
    }

    private function functionCatalog(ConnectionInterface $connection, string $name): ?object
    {
        return $connection->selectOne(<<<'SQL'
SELECT p.prosrc, pg_get_function_identity_arguments(p.oid) AS identity_arguments,
       pg_get_function_result(p.oid) AS result, l.lanname AS language, p.provolatile AS volatility,
       p.prosecdef AS security_definer, p.proowner = relation.relowner AS owner_matches_relation,
       p.proconfig, p.proisstrict AS strict, p.proleakproof AS leakproof,
       p.proparallel AS parallel, p.prokind AS kind
FROM pg_proc p
JOIN pg_language l ON l.oid = p.prolang
JOIN pg_class relation ON relation.oid = to_regclass(current_schema() || '.immutable_audit_events')
WHERE p.pronamespace = current_schema()::regnamespace AND p.proname = ?
  AND pg_get_function_identity_arguments(p.oid) = ''
SQL, [$name]);
    }

    private function triggerCatalog(ConnectionInterface $connection, string $name): ?object
    {
        return $connection->selectOne(<<<'SQL'
SELECT t.tgname AS name, t.tgenabled AS enabled, t.tgisinternal AS internal,
       c.relname AS relation, p.proname AS function_name, t.tgtype AS type
FROM pg_trigger t
JOIN pg_class c ON c.oid = t.tgrelid
JOIN pg_proc p ON p.oid = t.tgfoid
WHERE t.tgname = ? AND c.relnamespace = current_schema()::regnamespace
SQL, [$name]);
    }

    private function sequenceCatalog(ConnectionInterface $connection): ?object
    {
        return $connection->selectOne(<<<'SQL'
SELECT s.data_type, s.start_value, s.min_value, s.max_value, s.increment_by, s.cycle, s.cache_size,
       c.relname AS owned_table, a.attname AS owned_column
FROM pg_sequences s
JOIN pg_class q ON q.relname = s.sequencename AND q.relnamespace = current_schema()::regnamespace
LEFT JOIN pg_depend d ON d.objid = q.oid AND d.deptype = 'a'
LEFT JOIN pg_class c ON c.oid = d.refobjid
LEFT JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
WHERE s.schemaname = current_schema() AND s.sequencename = 'immutable_audit_sequence'
SQL);
    }

    /** @param list<string> $columns */
    private function ensurePhaseBIndex(ConnectionInterface $connection, string $name, array $columns, string $predicate): void
    {
        if (! $this->indexIsValid($connection, $name, $columns, $predicate) && $this->indexExists($connection, $name)) {
            $connection->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
        if (! $this->indexIsValid($connection, $name, $columns, $predicate)) {
            $connection->statement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON immutable_audit_events (".implode(', ', $columns).") WHERE {$predicate}");
        }
        if (! $this->indexIsValid($connection, $name, $columns, $predicate)) {
            throw new RuntimeException('immutable_audit_invariant_index_invalid:'.$name);
        }
    }

    private function index(ConnectionInterface $connection, string $name): ?object
    {
        return $connection->selectOne(<<<'SQL'
SELECT i.indisvalid, i.indisready, i.indisunique,
       ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum ORDER BY k.ord) AS columns,
       pg_get_expr(i.indpred, i.indrelid) AS predicate, pg_get_indexdef(i.indexrelid) AS definition
FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace
SQL, [$name]);
    }

    /** @return array<string,string> */
    private function failureReasons(): array
    {
        return [
            'sequence' => 'immutable_audit_sequence_invalid',
            'allocator' => 'immutable_audit_allocator_invalid',
            'writer_guard_function' => 'immutable_audit_writer_guard_invalid',
            'writer_guard_trigger' => 'immutable_audit_writer_guard_invalid',
            'append_only_function' => 'immutable_audit_append_only_invalid',
            'append_only_trigger' => 'immutable_audit_append_only_invalid',
            'sequence_sync_function' => 'immutable_audit_sequence_sync_invalid',
            'sequence_sync_trigger' => 'immutable_audit_sequence_sync_invalid',
            'aggregate_index' => 'immutable_audit_aggregate_index_invalid',
            'legacy_index' => 'immutable_audit_legacy_index_invalid',
        ];
    }

    /** @param array<string,bool> $snapshot */
    private function snapshotFailureReason(array $snapshot): ?string
    {
        foreach ($this->failureReasons() as $invariant => $reason) {
            if (($snapshot[$invariant] ?? false) !== true) {
                return $reason;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function functionDescriptor(object $row): array
    {
        return [
            'prosrc' => trim(str_replace("\r\n", "\n", (string) $row->prosrc)),
            'identity_arguments' => (string) $row->identity_arguments,
            'result' => (string) $row->result,
            'language' => (string) $row->language,
            'volatility' => (string) $row->volatility,
            'security_definer' => $this->postgresBoolean($row->security_definer),
            'owner_matches_relation' => $this->postgresBoolean($row->owner_matches_relation),
            'config' => $this->postgresArray($row->proconfig),
            'strict' => $this->postgresBoolean($row->strict),
            'leakproof' => $this->postgresBoolean($row->leakproof),
            'parallel' => (string) $row->parallel,
            'kind' => (string) $row->kind,
        ];
    }

    /** @return array<string,mixed> */
    private function triggerDescriptor(object $row): array
    {
        return [
            'name' => (string) $row->name,
            'enabled' => (string) $row->enabled,
            'internal' => $this->postgresBoolean($row->internal),
            'relation' => (string) $row->relation,
            'function_name' => (string) $row->function_name,
            'type' => (int) $row->type,
        ];
    }

    /** @return array<string,mixed> */
    private function sequenceDescriptor(object $row): array
    {
        return [
            'data_type' => (string) $row->data_type,
            'start_value' => (string) $row->start_value,
            'min_value' => (string) $row->min_value,
            'max_value' => (string) $row->max_value,
            'increment_by' => (string) $row->increment_by,
            'cycle' => $this->postgresBoolean($row->cycle),
            'cache_size' => (string) $row->cache_size,
            'owned_table' => (string) $row->owned_table,
            'owned_column' => (string) $row->owned_column,
        ];
    }

    /** @return array<string,mixed> */
    private function indexDescriptor(object $index): array
    {
        return [
            'valid' => $this->postgresBoolean($index->indisvalid),
            'ready' => $this->postgresBoolean($index->indisready),
            'unique' => $this->postgresBoolean($index->indisunique),
            'columns' => $this->postgresArray($index->columns),
            'predicate' => $this->normalizeSql((string) $index->predicate),
            'definition' => $this->normalizeIndexDefinition((string) $index->definition),
        ];
    }

    /** @param list<string> $columns @return array<string,mixed> */
    private function expectedIndexDescriptor(string $name, array $columns, string $predicate): array
    {
        return [
            'valid' => true,
            'ready' => true,
            'unique' => true,
            'columns' => $columns,
            'predicate' => $this->normalizeSql($predicate),
            'definition' => $this->expectedIndexDefinition($name, $columns, $predicate),
        ];
    }

    /** @param array<string,mixed> $descriptor */
    private function descriptorHash(array $descriptor): string
    {
        return hash('sha256', json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<string> */
    private function postgresArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        return str_getcsv(trim($value, '{}'));
    }

    private function normalizeSql(string $sql): string
    {
        return strtolower((string) preg_replace('/[();\s"]+/', '', $sql));
    }

    private function normalizeIndexDefinition(string $definition): string
    {
        $definition = (string) preg_replace('/\s+ON\s+(?:(?:"[^"]+"|[a-z_][a-z0-9_$]*)\.)?/i', ' ON ', $definition, 1);

        return $this->normalizeSql($definition);
    }

    /** @param list<string> $columns */
    private function expectedIndexDefinition(string $name, array $columns, string $predicate): string
    {
        return $this->normalizeSql("CREATE UNIQUE INDEX {$name} ON immutable_audit_events USING btree (".implode(', ', $columns).") WHERE {$predicate}");
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
