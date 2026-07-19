<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class ImmutableAuditRolloutService
{
    public const PHASE_B_WRITER_VERSION = 2;

    public function installCompatibilityPhase(ConnectionInterface $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('CREATE SEQUENCE IF NOT EXISTS immutable_audit_sequence');
        $connection->statement("CREATE TABLE IF NOT EXISTS immutable_audit_rollout (singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton), phase text NOT NULL CHECK (phase IN ('phase_a', 'phase_b')), writer_version integer NOT NULL, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $connection->statement("INSERT INTO immutable_audit_rollout (singleton, phase, writer_version) VALUES (true, 'phase_a', 1) ON CONFLICT (singleton) DO NOTHING");
        $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION immutable_audit_allocate_compatible_sequence() RETURNS bigint AS $$
DECLARE allocated bigint;
BEGIN
    LOCK TABLE immutable_audit_events IN SHARE ROW EXCLUSIVE MODE;
    SELECT COALESCE(MAX(sequence_id), 0) + 2 INTO allocated FROM immutable_audit_events;
    RETURN allocated;
END;
$$ LANGUAGE plpgsql VOLATILE;
CREATE OR REPLACE FUNCTION immutable_audit_allocate_sequence() RETURNS bigint AS $$
DECLARE rollout_phase text;
BEGIN
    SELECT phase INTO rollout_phase FROM immutable_audit_rollout WHERE singleton = true;
    IF rollout_phase = 'phase_b' THEN RETURN nextval('immutable_audit_sequence'); END IF;
    RETURN immutable_audit_allocate_compatible_sequence();
END;
$$ LANGUAGE plpgsql VOLATILE;
CREATE OR REPLACE FUNCTION immutable_audit_sync_sequence_after_insert() RETURNS trigger AS $$
BEGIN
    PERFORM setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), NEW.sequence_id), true);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS immutable_audit_sequence_sync ON immutable_audit_events;
CREATE TRIGGER immutable_audit_sequence_sync AFTER INSERT ON immutable_audit_events
FOR EACH ROW EXECUTE FUNCTION immutable_audit_sync_sequence_after_insert();
SQL);
    }

    public function cutover(ConnectionInterface $connection, bool $enabled, int $confirmedWriterVersion): void
    {
        if (! $enabled || $confirmedWriterVersion !== self::PHASE_B_WRITER_VERSION) {
            throw new RuntimeException('immutable_audit_phase_b_writer_fence_not_confirmed');
        }
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('immutable_audit_phase_b_requires_postgresql');
        }
        $connection->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', ['immutable_audit_phase_b_cutover']);
        try {
            $phase = $connection->table('immutable_audit_rollout')->where('singleton', true)->value('phase');
            if (! in_array($phase, ['phase_a', 'phase_b'], true)) {
                throw new RuntimeException('immutable_audit_phase_a_not_installed');
            }
            $this->ensurePhaseBIndex($connection, 'immutable_audit_source_event_aggregate_unique', ['organization_id', 'domain', 'subject_type', 'subject_id', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL');
            $this->ensurePhaseBIndex($connection, 'immutable_audit_source_event_legacy_unique', ['organization_id', 'domain', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)');

            if ($phase === 'phase_b') {
                return;
            }
            $connection->transaction(function () use ($connection): void {
                $connection->statement('LOCK TABLE immutable_audit_events IN ACCESS EXCLUSIVE MODE');
                $connection->statement("SELECT setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), COALESCE((SELECT MAX(sequence_id) FROM immutable_audit_events), 1)), EXISTS (SELECT 1 FROM immutable_audit_events))");
                $connection->table('immutable_audit_rollout')->where('singleton', true)->update(['phase' => 'phase_b', 'writer_version' => self::PHASE_B_WRITER_VERSION, 'updated_at' => now()]);
                $connection->statement('DROP INDEX IF EXISTS immutable_audit_source_event_unique');
            });
        } finally {
            $connection->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', ['immutable_audit_phase_b_cutover']);
        }
    }

    /** @param list<string> $columns */
    private function ensurePhaseBIndex(ConnectionInterface $connection, string $name, array $columns, string $predicate): void
    {
        $index = $connection->selectOne(<<<'SQL'
SELECT i.indisvalid, i.indisready, i.indisunique,
       ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum ORDER BY k.ord) AS columns,
       pg_get_expr(i.indpred, i.indrelid) AS predicate,
       pg_get_indexdef(i.indexrelid) AS definition
FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace
SQL, [$name]);
        $valid = $index !== null
            && $this->postgresBoolean($index->indisvalid)
            && $this->postgresBoolean($index->indisready)
            && $this->postgresBoolean($index->indisunique)
            && $this->postgresArray($index->columns) === $columns
            && $this->normalizeSql((string) $index->predicate) === $this->normalizeSql($predicate)
            && $this->normalizeIndexDefinition((string) $index->definition) === $this->expectedIndexDefinition($name, $columns, $predicate);

        if (! $valid && $index !== null) {
            $connection->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
        if (! $valid) {
            $columnSql = implode(', ', $columns);
            $connection->statement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON immutable_audit_events ({$columnSql}) WHERE {$predicate}");
        }
        $verified = $connection->selectOne('SELECT indisvalid, indisready FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$name]);
        if ($verified === null || ! $this->postgresBoolean($verified->indisvalid) || ! $this->postgresBoolean($verified->indisready)) {
            throw new RuntimeException('immutable_audit_phase_b_index_not_ready:'.$name);
        }
    }

    /** @return list<string> */
    private function postgresArray(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return str_getcsv(trim($value, '{}'));
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
