<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

final class ImmutableAuditRolloutService
{
    public const PHASE_B_WRITER_VERSION = 2;

    public function __construct(
        private readonly ImmutableAuditWriterCredential $credential = new ImmutableAuditWriterCredential,
    ) {}

    public function installCompatibilityPhase(ConnectionInterface $connection, int $maximumPhaseAHours, string $writerSecret): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }
        $credentialHash = $this->credential->fingerprint($writerSecret);
        $hours = max(1, min($maximumPhaseAHours, 168));

        $connection->statement('CREATE SEQUENCE IF NOT EXISTS immutable_audit_sequence');
        $connection->statement("CREATE TABLE IF NOT EXISTS immutable_audit_rollout (singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton), phase text NOT NULL CHECK (phase IN ('phase_a', 'phase_b')), writer_version integer NOT NULL, writer_credential_hash char(64) NULL, phase_a_expires_at timestamptz NULL, drain_confirmed_at timestamptz NULL, drain_marker uuid NULL, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $connection->statement("INSERT INTO immutable_audit_rollout (singleton, phase, writer_version, writer_credential_hash, phase_a_expires_at) VALUES (true, 'phase_a', 1, ?, clock_timestamp() + make_interval(hours => ?)) ON CONFLICT (singleton) DO NOTHING", [$credentialHash, $hours]);
        $connection->statement('UPDATE immutable_audit_rollout SET writer_credential_hash = ?, phase_a_expires_at = COALESCE(phase_a_expires_at, clock_timestamp() + make_interval(hours => ?)), updated_at = clock_timestamp() WHERE singleton = true', [$credentialHash, $hours]);
        $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION immutable_audit_allocate_sequence() RETURNS bigint AS $$
DECLARE rollout_phase text;
BEGIN
    SELECT phase INTO rollout_phase FROM immutable_audit_rollout WHERE singleton = true;
    IF rollout_phase <> 'phase_b' THEN
        RAISE EXCEPTION 'immutable_audit_writer_not_ready' USING ERRCODE = '55000';
    END IF;
    RETURN nextval('immutable_audit_sequence');
END;
$$ LANGUAGE plpgsql VOLATILE;
CREATE OR REPLACE FUNCTION immutable_audit_sync_sequence_after_insert() RETURNS trigger AS $$
BEGIN
    PERFORM setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), NEW.sequence_id), true);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION immutable_audit_writer_guard() RETURNS trigger AS $$
DECLARE rollout immutable_audit_rollout%ROWTYPE;
BEGIN
    SELECT * INTO rollout FROM immutable_audit_rollout WHERE singleton = true;
    IF rollout.phase = 'phase_a' AND rollout.phase_a_expires_at IS NOT NULL AND clock_timestamp() > rollout.phase_a_expires_at THEN
        RAISE EXCEPTION 'immutable_audit_phase_a_expired' USING ERRCODE = '55000';
    END IF;
    IF rollout.phase = 'phase_b' AND (
        COALESCE(current_setting('most.immutable_audit_writer_version', true), '') <> '2'
        OR rollout.writer_credential_hash IS NULL
        OR encode(sha256(convert_to('immutable-audit-writer-credential:' || COALESCE(current_setting('most.immutable_audit_writer_credential', true), ''), 'UTF8')), 'hex') <> rollout.writer_credential_hash
    ) THEN
        RAISE EXCEPTION 'immutable_audit_writer_version_rejected' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS immutable_audit_writer_guard ON immutable_audit_events;
CREATE TRIGGER immutable_audit_writer_guard BEFORE INSERT ON immutable_audit_events
FOR EACH ROW EXECUTE FUNCTION immutable_audit_writer_guard();
DROP TRIGGER IF EXISTS immutable_audit_sequence_sync ON immutable_audit_events;
CREATE TRIGGER immutable_audit_sequence_sync AFTER INSERT ON immutable_audit_events
FOR EACH ROW EXECUTE FUNCTION immutable_audit_sync_sequence_after_insert();
SQL);
    }

    public function confirmDrain(ConnectionInterface $connection, bool $enabled): void
    {
        if (! $enabled || $connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('immutable_audit_drain_confirmation_rejected');
        }
        $updated = $connection->table('immutable_audit_rollout')->where('singleton', true)->update([
            'drain_confirmed_at' => new Expression('clock_timestamp()'),
            'drain_marker' => (string) Str::uuid(),
            'updated_at' => new Expression('clock_timestamp()'),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('immutable_audit_rollout_not_installed');
        }
    }

    /** @return array{phase:?string,phase_a_expires_at:mixed,overdue:bool} */
    public function status(ConnectionInterface $connection): array
    {
        if (! $connection->getSchemaBuilder()->hasTable('immutable_audit_rollout')) {
            return ['phase' => null, 'phase_a_expires_at' => null, 'overdue' => false];
        }
        $row = $connection->table('immutable_audit_rollout')->where('singleton', true)->first();
        $expiresAt = $row?->phase_a_expires_at ?? null;

        return [
            'phase' => isset($row->phase) ? (string) $row->phase : null,
            'phase_a_expires_at' => $expiresAt,
            'overdue' => isset($row->phase) && $row->phase === 'phase_a' && $expiresAt !== null && Carbon::parse($expiresAt)->isPast(),
        ];
    }

    public function cutover(ConnectionInterface $connection, bool $enabled, int $confirmedWriterVersion, string $writerSecret, int $drainTtlMinutes = 15): void
    {
        if (! $enabled || $confirmedWriterVersion !== self::PHASE_B_WRITER_VERSION) {
            throw new RuntimeException('immutable_audit_phase_b_writer_fence_not_confirmed');
        }
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('immutable_audit_phase_b_requires_postgresql');
        }
        $credentialHash = $this->credential->fingerprint($writerSecret);
        $ttl = max(1, min($drainTtlMinutes, 60));
        $this->assertCutoverMarker($this->lockedRolloutMarker($connection, $ttl, false), $credentialHash);

        $this->preparePhaseBIndexes($connection);
        $connection->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', ['immutable_audit_writer_fence']);
        try {
            $connection->transaction(function () use ($connection, $credentialHash, $ttl): void {
                $connection->statement('LOCK TABLE immutable_audit_events IN ACCESS EXCLUSIVE MODE');
                $marker = $this->lockedRolloutMarker($connection, $ttl, true);
                $this->assertCutoverMarker($marker, $credentialHash);
                $this->verifyPhaseBIndexes($connection);
                if ((string) $marker->phase === 'phase_a') {
                    $connection->statement("SELECT setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), COALESCE((SELECT MAX(sequence_id) FROM immutable_audit_events), 1)), EXISTS (SELECT 1 FROM immutable_audit_events))");
                    $connection->statement('DROP INDEX IF EXISTS immutable_audit_source_event_unique');
                }
                $connection->table('immutable_audit_rollout')->where('singleton', true)->update([
                    'phase' => 'phase_b',
                    'writer_version' => self::PHASE_B_WRITER_VERSION,
                    'drain_confirmed_at' => null,
                    'drain_marker' => null,
                    'updated_at' => new Expression('clock_timestamp()'),
                ]);
            });
        } finally {
            $connection->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', ['immutable_audit_writer_fence']);
        }
    }

    private function preparePhaseBIndexes(ConnectionInterface $connection): void
    {
        $this->ensurePhaseBIndex($connection, 'immutable_audit_source_event_aggregate_unique', ['organization_id', 'domain', 'subject_type', 'subject_id', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL');
        $this->ensurePhaseBIndex($connection, 'immutable_audit_source_event_legacy_unique', ['organization_id', 'domain', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)');
    }

    private function verifyPhaseBIndexes(ConnectionInterface $connection): void
    {
        $this->verifyPhaseBIndex($connection, 'immutable_audit_source_event_aggregate_unique', ['organization_id', 'domain', 'subject_type', 'subject_id', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL');
        $this->verifyPhaseBIndex($connection, 'immutable_audit_source_event_legacy_unique', ['organization_id', 'domain', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)');
    }

    private function lockedRolloutMarker(ConnectionInterface $connection, int $ttlMinutes, bool $forUpdate): ?object
    {
        $forUpdateSql = $forUpdate ? 'FOR UPDATE' : '';

        return $connection->selectOne(<<<SQL
SELECT phase, writer_version, writer_credential_hash, drain_marker, drain_confirmed_at,
       drain_confirmed_at IS NOT NULL
       AND drain_confirmed_at >= clock_timestamp() - make_interval(mins => CAST(? AS integer)) AS drain_fresh
FROM immutable_audit_rollout WHERE singleton = true
{$forUpdateSql}
SQL, [$ttlMinutes]);
    }

    private function assertCutoverMarker(?object $marker, string $credentialHash): void
    {
        if ($marker === null || ! in_array($marker->phase ?? null, ['phase_a', 'phase_b'], true)) {
            throw new RuntimeException('immutable_audit_phase_a_not_installed');
        }
        if (! isset($marker->writer_credential_hash) || ! hash_equals((string) $marker->writer_credential_hash, $credentialHash)) {
            throw new RuntimeException('immutable_audit_phase_b_writer_secret_rejected');
        }
        if (! isset($marker->drain_marker) || ! $this->postgresBoolean($marker->drain_fresh ?? false)) {
            throw new RuntimeException('immutable_audit_phase_b_drain_marker_required');
        }
    }

    /** @param list<string> $columns */
    private function ensurePhaseBIndex(ConnectionInterface $connection, string $name, array $columns, string $predicate): void
    {
        $index = $this->phaseBIndex($connection, $name);
        if (! $this->phaseBIndexIsValid($index, $name, $columns, $predicate) && $index !== null) {
            $connection->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
        if (! $this->phaseBIndexIsValid($index, $name, $columns, $predicate)) {
            $columnSql = implode(', ', $columns);
            $connection->statement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON immutable_audit_events ({$columnSql}) WHERE {$predicate}");
        }
        $this->verifyPhaseBIndex($connection, $name, $columns, $predicate);
    }

    /** @param list<string> $columns */
    private function verifyPhaseBIndex(ConnectionInterface $connection, string $name, array $columns, string $predicate): void
    {
        if (! $this->phaseBIndexIsValid($this->phaseBIndex($connection, $name), $name, $columns, $predicate)) {
            throw new RuntimeException('immutable_audit_phase_b_index_not_ready:'.$name);
        }
    }

    private function phaseBIndex(ConnectionInterface $connection, string $name): ?object
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

    /** @param list<string> $columns */
    private function phaseBIndexIsValid(?object $index, string $name, array $columns, string $predicate): bool
    {
        return $index !== null
            && $this->postgresBoolean($index->indisvalid)
            && $this->postgresBoolean($index->indisready)
            && $this->postgresBoolean($index->indisunique)
            && $this->postgresArray($index->columns) === $columns
            && $this->normalizeSql((string) $index->predicate) === $this->normalizeSql($predicate)
            && $this->normalizeIndexDefinition((string) $index->definition) === $this->expectedIndexDefinition($name, $columns, $predicate);
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
