<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use DateTimeImmutable;
use LogicException;
use Throwable;

final class AssistantStatusSnapshotEpoch
{
    public const CHANGE_TABLE = 'ai_assistant_status_snapshot_changes';
    public const CONTROL_TABLE = 'ai_assistant_status_snapshot_control';
    public const FUNCTION_NAME = 'track_assistant_status_snapshot_mutation';
    public const TRIGGER_NAME = 'assistant_status_snapshot_mutation';
    public const EXCLUDED_TABLES = ['cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'sessions', 'ai_rag_embedding_checkpoints', self::CHANGE_TABLE, self::CONTROL_TABLE];
    public const FUNCTION_BODY = "\nBEGIN\n    INSERT INTO public.ai_assistant_status_snapshot_changes (xid, relation_oid) VALUES (pg_current_xact_id(), TG_RELID) ON CONFLICT (xid, relation_oid) DO NOTHING;\n    RETURN NULL;\nEND;\n";

    private const PURGE_BATCH_SIZE = 10000;

    public function __construct(private readonly ?string $connectionName = null) {}

    public function capture(?array $usedRelations = null): array
    {
        $relations = $this->normalizeRelations($usedRelations);
        $state = ['snapshot' => '', 'captured_at' => '', 'gc_generation' => -1, 'schema_fingerprint' => '', 'cacheable' => false, 'used_relations' => $relations];
        if ($usedRelations !== null && $relations === []) { return $state; }
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() === 0) { return $state; }
        $clock = $connection->selectOne("SELECT pg_current_snapshot()::text AS snapshot, LEAST(transaction_timestamp(), clock_timestamp())::text AS captured_at, current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only");
        $state['snapshot'] = (string) $clock->snapshot;
        $state['captured_at'] = (string) $clock->captured_at;
        if ($clock->isolation !== 'repeatable read' || $clock->read_only !== 'on') { return $state; }
        $schema = $this->schemaState($relations);
        $state['schema_fingerprint'] = (string) $schema->fingerprint;
        if ($schema->cacheable !== true) { return $state; }
        $control = $connection->selectOne('SELECT gc_generation FROM public.'.self::CONTROL_TABLE.' WHERE id = 1');
        if ($control === null) { return $state; }
        $state['gc_generation'] = (int) $control->gc_generation;
        $state['cacheable'] = true;

        return $state;
    }

    public function isValid(array $state, int $ttlSeconds = 300): bool
    {
        if (($state['cacheable'] ?? null) !== true || $ttlSeconds <= 0
            || ! is_string($state['snapshot'] ?? null) || ! is_string($state['captured_at'] ?? null)
            || ! is_string($state['schema_fingerprint'] ?? null) || ! is_int($state['gc_generation'] ?? null)
            || ! array_key_exists('used_relations', $state) || $state['used_relations'] !== null && ! is_array($state['used_relations'])) { return false; }
        $relations = $this->normalizeRelations($state['used_relations']);
        if ($relations !== $state['used_relations'] || $relations === []) { return false; }
        if (! $this->validSnapshot($state['snapshot']) || $state['captured_at'] === '') { return false; }
        try {
            new DateTimeImmutable($state['captured_at']);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) { return false; }
        } catch (Throwable) { return false; }
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() === 0) { return false; }
        $mode = $connection->selectOne("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only");
        if ($mode->isolation !== 'repeatable read' || $mode->read_only !== 'on') { return false; }
        $schema = $this->schemaState($relations);
        if ($schema->cacheable !== true || ! hash_equals((string) $schema->fingerprint, $state['schema_fingerprint'])) { return false; }
        $valid = $connection->selectOne('SELECT gc_generation = ? AND clock_timestamp() >= ?::timestamptz '
            .'AND clock_timestamp() < ?::timestamptz + make_interval(secs => ?) '
            .'AND pg_snapshot_xmax(?::pg_snapshot) <= pg_snapshot_xmax(pg_current_snapshot()) '
            .'AND NOT EXISTS (SELECT 1 FROM public.'.self::CHANGE_TABLE.' e WHERE e.relation_oid = ANY(?::oid[]) AND e.xid >= pg_snapshot_xmin(?::pg_snapshot) '
            .'AND NOT pg_visible_in_snapshot(e.xid, ?::pg_snapshot)) AS valid '
            .'FROM public.'.self::CONTROL_TABLE.' WHERE id = 1',
            [$state['gc_generation'], $state['captured_at'], $state['captured_at'], $ttlSeconds, $state['snapshot'], $schema->relation_oids, $state['snapshot'], $state['snapshot']]);

        return $valid !== null && $valid->valid === true;
    }

    public function purge(int $retentionSeconds = 600): int
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0 || $retentionSeconds < 0) {
            throw new LogicException('assistant_snapshot_purge_requires_separate_writable_transaction');
        }

        return $connection->transaction(function () use ($connection, $retentionSeconds): int {
            $deleted = $connection->delete('DELETE FROM public.'.self::CHANGE_TABLE.' WHERE ctid = ANY(ARRAY('
                .'SELECT ctid FROM public.'.self::CHANGE_TABLE.' WHERE created_at < statement_timestamp() - make_interval(secs => ?) '
                .'ORDER BY created_at LIMIT ?))', [$retentionSeconds, self::PURGE_BATCH_SIZE]);
            if ($deleted > 0) {
                $updated = $connection->update('UPDATE public.'.self::CONTROL_TABLE.' SET gc_generation = gc_generation + 1 WHERE id = 1');
                if ($updated !== 1) { throw new LogicException('assistant_snapshot_control_missing'); }
            }

            return $deleted;
        });
    }

    private function connection(): Connection
    {
        return DB::connection($this->connectionName);
    }

    private function validSnapshot(string $snapshot): bool
    {
        if (preg_match('/^(0|[1-9][0-9]{0,19}):(0|[1-9][0-9]{0,19}):((?:[1-9][0-9]{0,19}(?:,[1-9][0-9]{0,19})*)?)$/D', $snapshot, $parts) !== 1) { return false; }
        $compare = static fn (string $left, string $right): int => strlen($left) <=> strlen($right) ?: strcmp($left, $right);
        $maximum = '18446744073709551615';
        if ($compare($parts[1], $maximum) > 0 || $compare($parts[2], $maximum) > 0 || $compare($parts[1], $parts[2]) > 0) { return false; }
        $previous = null;
        foreach ($parts[3] === '' ? [] : explode(',', $parts[3]) as $xid) {
            if ($compare($xid, $parts[1]) < 0 || $compare($xid, $parts[2]) >= 0 || $previous !== null && $compare($previous, $xid) >= 0) { return false; }
            $previous = $xid;
        }

        return true;
    }

    private function normalizeRelations(?array $relations): ?array
    {
        if ($relations === null) { return null; }
        $normalized = [];
        foreach ($relations as $relation) {
            if (! is_string($relation) || preg_match('/^public\.([a-z_][a-z0-9_]*)$/D', $relation, $match) !== 1) { return []; }
            $normalized['public.'.$match[1]] = true;
        }
        $normalized = array_keys($normalized);
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function schemaState(?array $usedRelations = null): object
    {
        $excluded = implode(', ', array_map(static fn (string $table): string => "'".$table."'", self::EXCLUDED_TABLES));
        $names = $usedRelations === null ? [] : array_map(static fn (string $relation): string => substr($relation, 7), $usedRelations);
        $roots = $usedRelations === null ? 'TRUE' : ($names === [] ? 'FALSE' : 'relname IN ('.implode(', ', array_fill(0, count($names), '?')).')');
        $complete = $usedRelations === null ? 'TRUE' : '(SELECT COUNT(*) FROM roots) = '.count($names);
        $resolution = $usedRelations === null ? 'NOT EXISTS (SELECT 1 FROM roots r JOIN pg_class c ON c.oid = r.oid WHERE to_regclass(c.relname::text) IS DISTINCT FROM r.oid)' : 'TRUE';
        $sql = <<<'SQL'
WITH RECURSIVE relations AS (
    SELECT c.oid, c.relname, c.relkind,
        concat_ws(':', c.oid, c.relname, c.relkind, c.relrowsecurity, c.relforcerowsecurity,
            (SELECT jsonb_agg(jsonb_build_array(a.attnum, a.attname, a.atttypid, a.atttypmod, a.attnotnull, a.attisdropped, a.attgenerated, a.attidentity, a.attcollation) ORDER BY a.attnum)::text FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum > 0),
            CASE WHEN c.relkind IN ('v', 'm') THEN pg_get_viewdef(c.oid, true) ELSE '' END, pg_get_expr(c.relpartbound, c.oid),
            (SELECT jsonb_agg(jsonb_build_array(p.polname, p.polcmd, p.polpermissive, p.polroles, pg_get_expr(p.polqual, p.polrelid), pg_get_expr(p.polwithcheck, p.polrelid)) ORDER BY p.oid)::text FROM pg_policy p WHERE p.polrelid = c.oid)) AS definition
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'f') AND c.relname NOT IN (__EXCLUDED__)
), roots AS (SELECT oid FROM relations WHERE __ROOTS__), dependency_edges AS (
    SELECT w.ev_class AS source_oid, d.refobjid AS target_oid
    FROM pg_rewrite w JOIN pg_depend d ON d.classid = 'pg_rewrite'::regclass AND d.objid = w.oid AND d.refclassid = 'pg_class'::regclass
    WHERE d.refobjid <> w.ev_class
    UNION ALL SELECT inhparent, inhrelid FROM pg_inherits
), required AS (
    SELECT oid FROM roots
    UNION SELECT e.target_oid FROM required r JOIN dependency_edges e ON e.source_oid = r.oid
)
SELECT encode(sha256(convert_to(concat_ws('|', current_user, current_setting('TimeZone'), current_setting('DateStyle'), current_setting('search_path'), current_setting('row_security'),
    to_regclass('public.ai_assistant_status_snapshot_changes'), to_regclass('public.ai_assistant_status_snapshot_changes')::oid,
    to_regclass('public.ai_assistant_status_snapshot_control')::oid, to_regprocedure(?)::oid,
    COALESCE((SELECT string_agg(definition, '|' ORDER BY oid) FROM relations), ''), COALESCE(pg_get_functiondef(to_regprocedure(?)), '')), 'UTF8')), 'hex') AS fingerprint,
    COALESCE((SELECT array_agg(c.oid ORDER BY c.oid) FROM required r JOIN pg_class c ON c.oid = r.oid WHERE c.relkind IN ('r', 'p')), ARRAY[]::oid[])::text AS relation_oids,
    to_regclass('public.ai_assistant_status_snapshot_changes') IS NOT NULL
    AND to_regclass('public.ai_assistant_status_snapshot_control') IS NOT NULL
    AND EXISTS (SELECT 1 FROM pg_proc f WHERE f.oid = to_regprocedure(?) AND f.prosrc = ? AND f.prosecdef AND f.proconfig = ARRAY['search_path=pg_catalog']::text[])
    AND (SELECT COUNT(*) = 3 FROM pg_attribute a WHERE a.attrelid = to_regclass('public.ai_assistant_status_snapshot_changes') AND NOT a.attisdropped AND a.attnotnull AND ((a.attname = 'xid' AND a.atttypid = 'xid8'::regtype) OR (a.attname = 'relation_oid' AND a.atttypid = 'oid'::regtype) OR (a.attname = 'created_at' AND a.atttypid = 'timestamptz'::regtype)))
    AND (SELECT COUNT(*) = 2 FROM pg_attribute a WHERE a.attrelid = to_regclass('public.ai_assistant_status_snapshot_control') AND NOT a.attisdropped AND a.attnotnull AND ((a.attname = 'id' AND a.atttypid = 'int2'::regtype) OR (a.attname = 'gc_generation' AND a.atttypid = 'int8'::regtype)))
    AND __COMPLETE__
    AND __RESOLUTION__
    AND NOT EXISTS (SELECT 1 FROM required r JOIN pg_class c ON c.oid = r.oid JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relkind NOT IN ('r', 'p') OR n.nspname <> 'public' OR c.relname IN (__EXCLUDED__) OR c.relrowsecurity)
    AND NOT EXISTS (SELECT 1 FROM required r JOIN pg_rewrite w ON w.ev_class = r.oid
        JOIN pg_depend d ON d.classid = 'pg_rewrite'::regclass AND d.objid = w.oid AND d.refclassid = 'pg_proc'::regclass
        JOIN pg_proc f ON f.oid = d.refobjid JOIN pg_namespace n ON n.oid = f.pronamespace WHERE n.nspname <> 'pg_catalog' OR f.provolatile <> 'i')
    AND NOT EXISTS (
        SELECT 1 FROM relations r WHERE r.relkind IN ('r', 'p') AND NOT EXISTS (
            SELECT 1 FROM pg_trigger t WHERE t.tgrelid = r.oid AND t.tgname = ? AND t.tgfoid = to_regprocedure(?)
                AND NOT t.tgisinternal AND t.tgtype = 60 AND t.tgenabled = 'A' AND t.tgconstraint = 0 AND t.tgnargs = 0 AND t.tgattr::text = '' AND t.tgqual IS NULL
        )
    )
    AS cacheable
SQL;

        return $this->connection()->selectOne(str_replace(['__EXCLUDED__', '__ROOTS__', '__COMPLETE__', '__RESOLUTION__'], [$excluded, $roots, $complete, $resolution], $sql),
            [...$names, 'public.'.self::FUNCTION_NAME.'()', 'public.'.self::FUNCTION_NAME.'()', 'public.'.self::FUNCTION_NAME.'()', self::FUNCTION_BODY, self::TRIGGER_NAME, 'public.'.self::FUNCTION_NAME.'()']);
    }
}
