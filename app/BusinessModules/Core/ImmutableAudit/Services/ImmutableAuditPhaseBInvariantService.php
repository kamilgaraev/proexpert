<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class ImmutableAuditPhaseBInvariantService
{
    private const BASELINE_TABLE = 'immutable_audit_invariant_baselines';

    private const ALLOCATOR_BODY = <<<'SQL'
DECLARE rollout_phase text;
BEGIN
    SELECT phase INTO rollout_phase FROM immutable_audit_rollout WHERE singleton = true;
    IF rollout_phase <> 'phase_b' THEN
        RAISE EXCEPTION 'immutable_audit_writer_not_ready' USING ERRCODE = '55000';
    END IF;
    RETURN nextval('immutable_audit_sequence');
END;
SQL;

    private const SEQUENCE_SYNC_BODY = <<<'SQL'
BEGIN
    PERFORM setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), NEW.sequence_id), true);
    RETURN NEW;
END;
SQL;

    private const WRITER_GUARD_BODY = <<<'SQL'
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
SQL;

    private const APPEND_ONLY_BODY = <<<'SQL'
BEGIN
    RAISE EXCEPTION 'immutable audit records are append-only' USING ERRCODE = '55000';
END;
SQL;

    /** @param null|Closure(ConnectionInterface):array<string,bool> $snapshotLoader */
    public function __construct(
        private readonly ?Closure $snapshotLoader = null,
    ) {}

    public function failureReason(ConnectionInterface $connection): ?string
    {
        if ($this->snapshotLoader !== null) {
            return $this->snapshotFailureReason(($this->snapshotLoader)($connection));
        }
        if ($connection->getDriverName() !== 'pgsql' || ! $connection->getSchemaBuilder()->hasTable(self::BASELINE_TABLE)) {
            return 'immutable_audit_invariant_baseline_missing';
        }

        $baseline = $connection->table(self::BASELINE_TABLE)->pluck('fingerprint', 'invariant')->all();
        $current = $this->currentFingerprints($connection);
        foreach ($this->failureReasons() as $invariant => $reason) {
            if (! isset($baseline[$invariant], $current[$invariant])
                || ! hash_equals((string) $baseline[$invariant], $current[$invariant])) {
                return $reason;
            }
        }

        return null;
    }

    public function installCanonicalCore(ConnectionInterface $connection): void
    {
        $connection->statement('CREATE SEQUENCE IF NOT EXISTS immutable_audit_sequence AS bigint INCREMENT BY 1 MINVALUE 1 NO MAXVALUE START WITH 1 CACHE 1 NO CYCLE');
        $connection->statement('ALTER SEQUENCE immutable_audit_sequence AS bigint INCREMENT BY 1 MINVALUE 1 NO MAXVALUE CACHE 1 NO CYCLE OWNED BY immutable_audit_events.sequence_id');
        $connection->statement('CREATE TABLE IF NOT EXISTS '.self::BASELINE_TABLE.' (invariant text PRIMARY KEY, fingerprint char(64) NOT NULL, captured_at timestamptz NOT NULL DEFAULT clock_timestamp())');
        $connection->unprepared($this->canonicalCoreSql());
        $this->assertCanonicalCore($connection);
    }

    public function captureBaseline(ConnectionInterface $connection, bool $requirePhaseBIndexes): void
    {
        $this->assertCanonicalCore($connection);
        $fingerprints = $this->currentFingerprints($connection);
        $required = array_keys($this->failureReasons());
        if (! $requirePhaseBIndexes) {
            $required = array_values(array_diff($required, ['aggregate_index', 'legacy_index']));
        }
        foreach ($required as $invariant) {
            $fingerprint = $fingerprints[$invariant] ?? null;
            if (! is_string($fingerprint) || strlen($fingerprint) !== 64) {
                throw new RuntimeException('immutable_audit_invariant_verification_failed:'.$invariant);
            }
            $connection->table(self::BASELINE_TABLE)->updateOrInsert(
                ['invariant' => $invariant],
                ['fingerprint' => $fingerprint, 'captured_at' => $connection->raw('clock_timestamp()')],
            );
        }
    }

    public function repairPermanentInvariants(ConnectionInterface $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('immutable_audit_invariant_repair_requires_postgresql');
        }
        if ($connection->table('immutable_audit_rollout')->where('singleton', true)->value('phase') !== 'phase_b') {
            throw new RuntimeException('immutable_audit_invariant_repair_requires_phase_b');
        }
        $connection->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', ['immutable_audit_invariant_repair']);
        try {
            $this->installCanonicalCore($connection);
            $this->ensurePhaseBIndex($connection, 'immutable_audit_source_event_aggregate_unique', ['organization_id', 'domain', 'subject_type', 'subject_id', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL');
            $this->ensurePhaseBIndex($connection, 'immutable_audit_source_event_legacy_unique', ['organization_id', 'domain', 'source', 'source_event_id'], 'source_event_id IS NOT NULL AND (subject_type IS NULL OR subject_id IS NULL)');
            $this->captureBaseline($connection, true);
            if ($this->failureReason($connection) !== null) {
                throw new RuntimeException('immutable_audit_invariant_repair_verification_failed');
            }
        } finally {
            $connection->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', ['immutable_audit_invariant_repair']);
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

    /** @return array<string,string> */
    private function currentFingerprints(ConnectionInterface $connection): array
    {
        $fingerprints = [];
        foreach (['allocator' => 'immutable_audit_allocate_sequence', 'writer_guard_function' => 'immutable_audit_writer_guard', 'append_only_function' => 'immutable_audit_prevent_mutation', 'sequence_sync_function' => 'immutable_audit_sync_sequence_after_insert'] as $key => $function) {
            $row = $this->functionCatalog($connection, $function);
            if ($row !== null) {
                $fingerprints[$key] = $this->hashCatalogRow($row);
            }
        }
        foreach (['writer_guard_trigger' => 'immutable_audit_writer_guard', 'append_only_trigger' => 'immutable_audit_events_append_only', 'sequence_sync_trigger' => 'immutable_audit_sequence_sync'] as $key => $trigger) {
            $row = $this->triggerCatalog($connection, $trigger);
            if ($row !== null) {
                $fingerprints[$key] = $this->hashCatalogRow($row);
            }
        }
        $sequence = $connection->selectOne(<<<'SQL'
SELECT s.data_type, s.start_value, s.min_value, s.max_value, s.increment_by, s.cycle, s.cache_size,
       c.relname AS owned_table, a.attname AS owned_column
FROM pg_sequences s
JOIN pg_class q ON q.relname = s.sequencename AND q.relnamespace = current_schema()::regnamespace
LEFT JOIN pg_depend d ON d.objid = q.oid AND d.deptype = 'a'
LEFT JOIN pg_class c ON c.oid = d.refobjid
LEFT JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
WHERE s.schemaname = current_schema() AND s.sequencename = 'immutable_audit_sequence'
SQL);
        if ($sequence !== null) {
            $fingerprints['sequence'] = $this->hashCatalogRow($sequence);
        }
        foreach (['aggregate_index' => 'immutable_audit_source_event_aggregate_unique', 'legacy_index' => 'immutable_audit_source_event_legacy_unique'] as $key => $name) {
            $index = $this->index($connection, $name);
            if ($index !== null) {
                $fingerprints[$key] = $this->hashCatalogRow($index);
            }
        }

        return $fingerprints;
    }

    private function assertCanonicalCore(ConnectionInterface $connection): void
    {
        foreach ([
            'immutable_audit_allocate_sequence' => [self::ALLOCATOR_BODY, 'bigint', 'v'],
            'immutable_audit_writer_guard' => [self::WRITER_GUARD_BODY, 'trigger', 'v'],
            'immutable_audit_prevent_mutation' => [self::APPEND_ONLY_BODY, 'trigger', 'v'],
            'immutable_audit_sync_sequence_after_insert' => [self::SEQUENCE_SYNC_BODY, 'trigger', 'v'],
        ] as $name => [$body, $result, $volatility]) {
            $row = $this->functionCatalog($connection, $name);
            if ($row === null
                || $this->normalizeBody((string) $row->prosrc) !== $this->normalizeBody($body)
                || (string) $row->identity_arguments !== ''
                || (string) $row->result !== $result
                || (string) $row->language !== 'plpgsql'
                || (string) $row->volatility !== $volatility) {
                throw new RuntimeException('immutable_audit_canonical_function_invalid:'.$name);
            }
        }
        foreach ([
            'immutable_audit_writer_guard' => 'CREATE TRIGGER immutable_audit_writer_guard BEFORE INSERT ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_writer_guard()',
            'immutable_audit_events_append_only' => 'CREATE TRIGGER immutable_audit_events_append_only BEFORE UPDATE OR DELETE ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_prevent_mutation()',
            'immutable_audit_sequence_sync' => 'CREATE TRIGGER immutable_audit_sequence_sync AFTER INSERT ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_sync_sequence_after_insert()',
        ] as $name => $definition) {
            $row = $this->triggerCatalog($connection, $name);
            if ($row === null || $this->normalizeSql((string) $row->definition) !== $this->normalizeSql($definition)) {
                throw new RuntimeException('immutable_audit_canonical_trigger_invalid:'.$name);
            }
        }
    }

    private function canonicalCoreSql(): string
    {
        return sprintf(<<<'SQL'
CREATE OR REPLACE FUNCTION immutable_audit_allocate_sequence() RETURNS bigint AS $function$
%s
$function$ LANGUAGE plpgsql VOLATILE;
CREATE OR REPLACE FUNCTION immutable_audit_sync_sequence_after_insert() RETURNS trigger AS $function$
%s
$function$ LANGUAGE plpgsql VOLATILE;
CREATE OR REPLACE FUNCTION immutable_audit_writer_guard() RETURNS trigger AS $function$
%s
$function$ LANGUAGE plpgsql VOLATILE;
CREATE OR REPLACE FUNCTION immutable_audit_prevent_mutation() RETURNS trigger AS $function$
%s
$function$ LANGUAGE plpgsql VOLATILE;
DROP TRIGGER IF EXISTS immutable_audit_writer_guard ON immutable_audit_events;
CREATE TRIGGER immutable_audit_writer_guard BEFORE INSERT ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_writer_guard();
DROP TRIGGER IF EXISTS immutable_audit_events_append_only ON immutable_audit_events;
CREATE TRIGGER immutable_audit_events_append_only BEFORE UPDATE OR DELETE ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_prevent_mutation();
DROP TRIGGER IF EXISTS immutable_audit_sequence_sync ON immutable_audit_events;
CREATE TRIGGER immutable_audit_sequence_sync AFTER INSERT ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_sync_sequence_after_insert();
SQL, self::ALLOCATOR_BODY, self::SEQUENCE_SYNC_BODY, self::WRITER_GUARD_BODY, self::APPEND_ONLY_BODY);
    }

    private function functionCatalog(ConnectionInterface $connection, string $name): ?object
    {
        return $connection->selectOne(<<<'SQL'
SELECT p.prosrc, pg_get_function_identity_arguments(p.oid) AS identity_arguments,
       pg_get_function_result(p.oid) AS result, l.lanname AS language, p.provolatile AS volatility
FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang
WHERE p.pronamespace = current_schema()::regnamespace AND p.proname = ?
  AND pg_get_function_identity_arguments(p.oid) = ''
SQL, [$name]);
    }

    private function triggerCatalog(ConnectionInterface $connection, string $name): ?object
    {
        return $connection->selectOne(<<<'SQL'
SELECT t.tgenabled AS enabled, t.tgisinternal AS internal, c.relname AS relation,
       p.proname AS function_name, pg_get_triggerdef(t.oid, false) AS definition
FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_proc p ON p.oid = t.tgfoid
WHERE t.tgname = ? AND c.relnamespace = current_schema()::regnamespace
SQL, [$name]);
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

    private function hashCatalogRow(object $row): string
    {
        $values = get_object_vars($row);
        ksort($values, SORT_STRING);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<string> */
    private function postgresArray(mixed $value): array
    {
        return is_string($value) ? str_getcsv(trim($value, '{}')) : [];
    }

    private function normalizeBody(string $body): string
    {
        return trim(str_replace("\r\n", "\n", $body));
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
