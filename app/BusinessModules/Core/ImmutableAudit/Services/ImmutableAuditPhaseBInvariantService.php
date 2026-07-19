<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;

final class ImmutableAuditPhaseBInvariantService
{
    /** @param null|Closure(ConnectionInterface):array<string,bool> $snapshotLoader */
    public function __construct(
        private readonly ?Closure $snapshotLoader = null,
    ) {}

    public function failureReason(ConnectionInterface $connection): ?string
    {
        $snapshot = $this->snapshotLoader !== null
            ? ($this->snapshotLoader)($connection)
            : $this->loadSnapshot($connection);

        foreach ([
            'sequence_exists' => 'immutable_audit_sequence_missing',
            'allocator_valid' => 'immutable_audit_allocator_invalid',
            'guard_trigger_valid' => 'immutable_audit_writer_guard_invalid',
            'aggregate_index_valid' => 'immutable_audit_aggregate_index_invalid',
            'legacy_index_valid' => 'immutable_audit_legacy_index_invalid',
        ] as $invariant => $reason) {
            if (($snapshot[$invariant] ?? false) !== true) {
                return $reason;
            }
        }

        return null;
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

    /** @return array<string,bool> */
    private function loadSnapshot(ConnectionInterface $connection): array
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return [];
        }
        $core = $connection->selectOne(<<<'SQL'
SELECT
    to_regclass(current_schema() || '.immutable_audit_sequence') IS NOT NULL AS sequence_exists,
    COALESCE((
        SELECT pg_get_functiondef(p.oid) LIKE '%RETURN nextval(''immutable_audit_sequence'');%'
           AND pg_get_functiondef(p.oid) LIKE '%immutable_audit_writer_not_ready%'
        FROM pg_proc p
        WHERE p.oid = to_regprocedure(current_schema() || '.immutable_audit_allocate_sequence()')
    ), false) AS allocator_valid,
    EXISTS (
        SELECT 1
        FROM pg_trigger t
        JOIN pg_proc p ON p.oid = t.tgfoid
        WHERE t.tgrelid = to_regclass(current_schema() || '.immutable_audit_events')
          AND t.tgname = 'immutable_audit_writer_guard'
          AND t.tgenabled = 'O'
          AND NOT t.tgisinternal
          AND p.proname = 'immutable_audit_writer_guard'
          AND p.pronamespace = current_schema()::regnamespace
          AND pg_get_functiondef(p.oid) LIKE '%most.immutable_audit_writer_version%'
          AND pg_get_functiondef(p.oid) LIKE '%most.immutable_audit_writer_credential%'
          AND pg_get_functiondef(p.oid) LIKE '%immutable_audit_writer_version_rejected%'
    ) AS guard_trigger_valid
SQL);

        return [
            'sequence_exists' => $this->postgresBoolean($core?->sequence_exists ?? false),
            'allocator_valid' => $this->postgresBoolean($core?->allocator_valid ?? false),
            'guard_trigger_valid' => $this->postgresBoolean($core?->guard_trigger_valid ?? false),
            'aggregate_index_valid' => $this->indexIsValid(
                $connection,
                'immutable_audit_source_event_aggregate_unique',
                ['organization_id', 'domain', 'subject_type', 'subject_id', 'source', 'source_event_id'],
                'source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL',
            ),
            'legacy_index_valid' => $this->indexIsValid(
                $connection,
                'immutable_audit_source_event_legacy_unique',
                ['organization_id', 'domain', 'source', 'source_event_id'],
                'source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)',
            ),
        ];
    }

    private function index(ConnectionInterface $connection, string $name): ?object
    {
        return $connection->selectOne(<<<'SQL'
SELECT i.indisvalid, i.indisready, i.indisunique,
       ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum ORDER BY k.ord) AS columns,
       pg_get_expr(i.indpred, i.indrelid) AS predicate,
       pg_get_indexdef(i.indexrelid) AS definition
FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace
SQL, [$name]);
    }

    /** @return list<string> */
    private function postgresArray(mixed $value): array
    {
        return is_string($value) ? str_getcsv(trim($value, '{}')) : [];
    }

    private function normalizeSql(string $sql): string
    {
        return strtolower((string) preg_replace('/[()\s"]+/', '', $sql));
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
