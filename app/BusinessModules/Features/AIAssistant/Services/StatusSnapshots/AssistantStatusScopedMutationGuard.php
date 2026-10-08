<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

use Illuminate\Database\Connection;
use LogicException;

final class AssistantStatusScopedMutationGuard
{
    public const CHANGE_TABLE = 'ai_assistant_status_snapshot_scoped_changes';

    public const TABLES = ['ai_rag_sources', 'ai_rag_chunks', 'ai_rag_status_sources', 'ai_rag_status_chunks', 'ai_rag_coverage_states'];

    public const ROW_TRIGGER = 'assistant_status_snapshot_scoped_mutation';

    public const ROW_FUNCTION = 'track_assistant_status_snapshot_scoped_mutation';

    public const TRUNCATE_TRIGGER = 'assistant_status_snapshot_scoped_truncate';

    public const TRUNCATE_FUNCTION = 'track_assistant_status_snapshot_scoped_truncate';

    public const WITNESS_FUNCTION = 'witness_assistant_status_snapshot_scope';

    public const ROW_BODY_PREFIX = "\nBEGIN\n    IF TG_TABLE_SCHEMA <> 'public' OR TG_TABLE_NAME NOT IN ('ai_rag_sources', 'ai_rag_chunks', 'ai_rag_status_sources', 'ai_rag_status_chunks', 'ai_rag_coverage_states') OR TG_OP NOT IN ('INSERT', 'UPDATE', 'DELETE') OR TG_LEVEL <> 'ROW' THEN\n        RAISE EXCEPTION 'assistant_snapshot_scoped_mutation_scope';\n    END IF;\n    IF TG_OP = 'UPDATE' AND TG_TABLE_NAME = 'ai_rag_sources' THEN\n        IF ROW(";

    public const ROW_BODY_SEPARATOR = ') IS NOT DISTINCT FROM ROW(';

    public const ROW_BODY_SUFFIX = ") THEN\n            RETURN NULL;\n        END IF;\n    END IF;\n    IF TG_OP <> 'INSERT' THEN\n        INSERT INTO public.ai_assistant_status_snapshot_scoped_changes (xid, relation_oid, organization_id) VALUES (pg_current_xact_id(), TG_RELID, COALESCE(OLD.organization_id, 0)) ON CONFLICT (xid, relation_oid, organization_id) DO NOTHING;\n    END IF;\n    IF TG_OP <> 'DELETE' THEN\n        INSERT INTO public.ai_assistant_status_snapshot_scoped_changes (xid, relation_oid, organization_id) VALUES (pg_current_xact_id(), TG_RELID, COALESCE(NEW.organization_id, 0)) ON CONFLICT (xid, relation_oid, organization_id) DO NOTHING;\n    END IF;\n    RETURN NULL;\nEND;\n";

    public const WITNESS_BODY = "\nDECLARE\n    guarded boolean;\n    expected_body text;\nBEGIN\n    SELECT \$scope_prefix\$".self::ROW_BODY_PREFIX."\$scope_prefix\$\n        || (SELECT string_agg('OLD.\"' || replace(a.attname, '\"', '\"\"') || '\"', ', ' ORDER BY a.attnum) FROM pg_catalog.pg_attribute a WHERE a.attrelid = to_regclass('public.ai_rag_sources') AND a.attnum > 0 AND NOT a.attisdropped AND a.attname NOT IN ('last_reconciled_at', 'updated_at'))\n        || \$scope_separator\$".self::ROW_BODY_SEPARATOR."\$scope_separator\$\n        || (SELECT string_agg('NEW.\"' || replace(a.attname, '\"', '\"\"') || '\"', ', ' ORDER BY a.attnum) FROM pg_catalog.pg_attribute a WHERE a.attrelid = to_regclass('public.ai_rag_sources') AND a.attnum > 0 AND NOT a.attisdropped AND a.attname NOT IN ('last_reconciled_at', 'updated_at'))\n        || \$scope_suffix\$".self::ROW_BODY_SUFFIX."\$scope_suffix\$ INTO expected_body;\n    SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_trigger t JOIN pg_catalog.pg_proc f ON f.oid = t.tgfoid CROSS JOIN public.ai_assistant_status_snapshot_control c\n        WHERE c.id = 1 AND t.tgrelid = target_relation AND t.tgname = 'assistant_status_snapshot_scoped_mutation'\n            AND t.tgfoid = pg_catalog.to_regprocedure('public.track_assistant_status_snapshot_scoped_mutation()')\n            AND NOT t.tgisinternal AND t.tgtype = 29 AND t.tgenabled = 'A' AND t.tgconstraint = 0 AND t.tgnargs = 0 AND t.tgattr::text = '' AND t.tgqual IS NULL\n            AND f.prosrc = expected_body AND c.scoped_guard_body = expected_body AND f.prosecdef AND f.proconfig = ARRAY['search_path=pg_catalog']::text[]\n            AND f.prorettype = 'trigger'::regtype AND f.prokind = 'f' AND f.pronargs = 0 AND f.provolatile = 'v' AND f.prolang = (SELECT oid FROM pg_catalog.pg_language WHERE lanname = 'plpgsql')) INTO guarded;\n    IF operation = 'TRUNCATE' OR NOT COALESCE(guarded, false) THEN\n        INSERT INTO public.ai_assistant_status_snapshot_scoped_changes (xid, relation_oid, organization_id) VALUES (pg_catalog.pg_current_xact_id(), target_relation, 0) ON CONFLICT (xid, relation_oid, organization_id) DO NOTHING;\n    END IF;\nEND;\n";

    public const WITNESS_BODY_HASH = '2e0dd4c24bb1ce9d6979b33b9011fef89a332b47bd92c19a6c4942db60371323';

    public const WITNESS_SQL = "    IF TG_TABLE_SCHEMA = 'public' AND TG_TABLE_NAME IN ('ai_rag_sources', 'ai_rag_chunks', 'ai_rag_status_sources', 'ai_rag_status_chunks', 'ai_rag_coverage_states') AND to_regclass('public.ai_assistant_status_snapshot_scoped_changes') IS NOT NULL THEN\n        IF EXISTS (SELECT 1 FROM pg_proc f WHERE f.oid = to_regprocedure('public.witness_assistant_status_snapshot_scope(oid,text)') AND encode(sha256(convert_to(f.prosrc, 'UTF8')), 'hex') = '".self::WITNESS_BODY_HASH."' AND f.prosecdef AND f.proconfig = ARRAY['search_path=pg_catalog']::text[] AND f.prorettype = 'void'::regtype AND f.prokind = 'f' AND f.pronargs = 2 AND f.proargtypes::text = '26 25' AND f.provolatile = 'v' AND f.prolang = (SELECT oid FROM pg_language WHERE lanname = 'plpgsql')) THEN\n            PERFORM public.witness_assistant_status_snapshot_scope(TG_RELID, TG_OP);\n        ELSE\n            INSERT INTO public.ai_assistant_status_snapshot_scoped_changes (xid, relation_oid, organization_id) VALUES (pg_current_xact_id(), TG_RELID, 0) ON CONFLICT (xid, relation_oid, organization_id) DO NOTHING;\n        END IF;\n    END IF;\n";

    public const TRUNCATE_BODY = "\nBEGIN\n    IF TG_TABLE_SCHEMA <> 'public' OR TG_TABLE_NAME NOT IN ('ai_rag_sources', 'ai_rag_chunks', 'ai_rag_status_sources', 'ai_rag_status_chunks', 'ai_rag_coverage_states') OR TG_OP <> 'TRUNCATE' OR TG_LEVEL <> 'STATEMENT' THEN\n        RAISE EXCEPTION 'assistant_snapshot_scoped_truncate_scope';\n    END IF;\n    INSERT INTO public.ai_assistant_status_snapshot_scoped_changes (xid, relation_oid, organization_id) VALUES (pg_current_xact_id(), TG_RELID, 0) ON CONFLICT (xid, relation_oid, organization_id) DO NOTHING;\n    RETURN NULL;\nEND;\n";

    public static function rowBody(array $sourceColumns): string
    {
        $old = implode(', ', array_map(static fn (string $column): string => 'OLD.'.AssistantStatusSourceMutationGuard::quoteColumn($column), $sourceColumns));
        $new = implode(', ', array_map(static fn (string $column): string => 'NEW.'.AssistantStatusSourceMutationGuard::quoteColumn($column), $sourceColumns));

        return self::ROW_BODY_PREFIX.$old.self::ROW_BODY_SEPARATOR.$new.self::ROW_BODY_SUFFIX;
    }

    public static function sourceColumns(Connection $connection): array
    {
        return array_map(static fn (object $column): string => (string) $column->attname, $connection->select("SELECT attname FROM pg_attribute WHERE attrelid = to_regclass('public.ai_rag_sources') AND attnum > 0 AND NOT attisdropped AND attname NOT IN ('last_reconciled_at', 'updated_at') ORDER BY attnum"));
    }

    public static function proof(Connection $connection): object
    {
        $installed = $connection->selectOne("SELECT to_regclass('public.ai_assistant_status_snapshot_scoped_changes') IS NOT NULL AND EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('public.ai_assistant_status_snapshot_control') AND attname = 'scoped_guard_body' AND attnum > 0 AND NOT attisdropped AND atttypid = 'text'::regtype AND attgenerated = '' AND attidentity = '') AS present");
        if ($installed === null || $installed->present !== true) {
            return (object) ['cacheable' => false, 'fingerprint' => '', 'relation_oids' => '{}'];
        }
        $columns = self::sourceColumns($connection);
        if ($columns === []) {
            return (object) ['cacheable' => false, 'fingerprint' => '', 'relation_oids' => '{}'];
        }
        $tableNames = '{'.implode(',', self::TABLES).'}';
        $body = self::rowBody($columns);
        $sql = <<<'SQL'
WITH scoped_relations AS (
    SELECT c.oid, c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relname = ANY(?::text[])
), scoped_functions AS (
    SELECT p.* FROM pg_proc p WHERE p.oid IN (to_regprocedure(?), to_regprocedure(?), to_regprocedure(?))
)
SELECT (SELECT array_agg(oid ORDER BY oid)::text FROM scoped_relations) AS relation_oids,
    encode(sha256(convert_to(concat_ws('|', to_regclass('public.ai_assistant_status_snapshot_scoped_changes')::oid,
        (SELECT scoped_guard_body FROM public.ai_assistant_status_snapshot_control WHERE id = 1),
        (SELECT array_agg(ROW(a.attnum, a.attname, a.atttypid, a.attnotnull, a.attisdropped, a.attgenerated, a.attidentity) ORDER BY a.attnum)::text FROM pg_attribute a WHERE a.attrelid = to_regclass('public.ai_assistant_status_snapshot_scoped_changes') AND a.attnum > 0),
        (SELECT string_agg(pg_get_indexdef(i.indexrelid), '|' ORDER BY i.indexrelid) FROM pg_index i WHERE i.indrelid = to_regclass('public.ai_assistant_status_snapshot_scoped_changes')),
        (SELECT string_agg(pg_get_functiondef(f.oid), '|' ORDER BY f.oid) FROM scoped_functions f),
        (SELECT string_agg(pg_get_triggerdef(t.oid), '|' ORDER BY t.tgrelid,t.tgname) FROM pg_trigger t JOIN scoped_relations r ON r.oid = t.tgrelid WHERE t.tgname IN (?, ?))), 'UTF8')), 'hex') AS fingerprint,
    (SELECT COUNT(*) = 5 FROM scoped_relations)
    AND EXISTS (SELECT 1 FROM public.ai_assistant_status_snapshot_control WHERE id = 1 AND scoped_guard_body = ?)
    AND EXISTS (SELECT 1 FROM pg_class c WHERE c.oid = to_regclass('public.ai_assistant_status_snapshot_scoped_changes') AND c.relkind = 'r' AND NOT c.relrowsecurity AND NOT c.relforcerowsecurity
        AND NOT EXISTS (SELECT 1 FROM pg_inherits i WHERE i.inhrelid = c.oid OR i.inhparent = c.oid)
        AND NOT EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgrelid = c.oid AND NOT t.tgisinternal))
    AND NOT EXISTS (SELECT 1 FROM scoped_relations r WHERE r.relkind <> 'r' OR EXISTS (SELECT 1 FROM pg_inherits i WHERE i.inhrelid = r.oid OR i.inhparent = r.oid)
        OR NOT EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = r.oid AND a.attname = 'organization_id' AND a.attnum > 0 AND NOT a.attisdropped AND a.attnotnull AND a.atttypid = 'int8'::regtype AND a.attgenerated = '' AND a.attidentity = ''))
    AND (SELECT COUNT(*) = 4 FROM pg_attribute a WHERE a.attrelid = to_regclass('public.ai_assistant_status_snapshot_scoped_changes') AND a.attnum > 0 AND NOT a.attisdropped AND a.attnotnull AND a.attgenerated = '' AND a.attidentity = ''
        AND ((a.attname = 'xid' AND a.atttypid = 'xid8'::regtype) OR (a.attname = 'relation_oid' AND a.atttypid = 'oid'::regtype) OR (a.attname = 'organization_id' AND a.atttypid = 'int8'::regtype) OR (a.attname = 'created_at' AND a.atttypid = 'timestamptz'::regtype)))
    AND EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = to_regclass('public.ai_assistant_status_snapshot_scoped_changes') AND i.indisprimary AND i.indisvalid AND i.indisready
        AND i.indnkeyatts = 3 AND i.indkey::text = (SELECT string_agg(a.attnum::text, ' ' ORDER BY CASE a.attname WHEN 'xid' THEN 1 WHEN 'relation_oid' THEN 2 ELSE 3 END) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attname IN ('xid', 'relation_oid', 'organization_id') AND NOT a.attisdropped) AND i.indpred IS NULL AND i.indexprs IS NULL)
    AND EXISTS (SELECT 1 FROM scoped_functions f WHERE f.oid = to_regprocedure(?) AND f.prosrc = ? AND f.prosecdef AND f.proconfig = ARRAY['search_path=pg_catalog']::text[] AND f.prorettype = 'trigger'::regtype AND f.prokind = 'f' AND f.pronargs = 0 AND f.provolatile = 'v' AND f.prolang = (SELECT oid FROM pg_language WHERE lanname = 'plpgsql'))
    AND EXISTS (SELECT 1 FROM scoped_functions f WHERE f.oid = to_regprocedure(?) AND f.prosrc = ? AND f.prosecdef AND f.proconfig = ARRAY['search_path=pg_catalog']::text[] AND f.prorettype = 'void'::regtype AND f.prokind = 'f' AND f.pronargs = 2 AND f.proargtypes::text = '26 25' AND f.provolatile = 'v' AND f.prolang = (SELECT oid FROM pg_language WHERE lanname = 'plpgsql'))
    AND NOT EXISTS (SELECT 1 FROM scoped_functions f CROSS JOIN LATERAL aclexplode(COALESCE(f.proacl, acldefault('f', f.proowner))) a WHERE f.oid = to_regprocedure('public.witness_assistant_status_snapshot_scope(oid,text)') AND a.grantee = 0 AND a.privilege_type = 'EXECUTE')
    AND NOT EXISTS (SELECT 1 FROM pg_proc f WHERE f.oid IN (to_regprocedure('public.track_assistant_status_snapshot_mutation()'), to_regprocedure('public.track_assistant_source_snapshot_semantic_mutation()')) AND NOT has_function_privilege(f.proowner, to_regprocedure('public.witness_assistant_status_snapshot_scope(oid,text)'), 'EXECUTE'))
    AND EXISTS (SELECT 1 FROM scoped_functions f WHERE f.oid = to_regprocedure(?) AND f.prosrc = ? AND f.prosecdef AND f.proconfig = ARRAY['search_path=pg_catalog']::text[] AND f.prorettype = 'trigger'::regtype AND f.prokind = 'f' AND f.pronargs = 0 AND f.provolatile = 'v' AND f.prolang = (SELECT oid FROM pg_language WHERE lanname = 'plpgsql'))
    AND NOT EXISTS (SELECT 1 FROM scoped_relations r WHERE NOT EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgrelid = r.oid AND t.tgname = ? AND t.tgfoid = to_regprocedure(?) AND NOT t.tgisinternal AND t.tgtype = 29 AND t.tgenabled = 'A' AND t.tgconstraint = 0 AND t.tgnargs = 0 AND t.tgattr::text = '' AND t.tgqual IS NULL)
        OR NOT EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgrelid = r.oid AND t.tgname = ? AND t.tgfoid = to_regprocedure(?) AND NOT t.tgisinternal AND t.tgtype = 32 AND t.tgenabled = 'A' AND t.tgconstraint = 0 AND t.tgnargs = 0 AND t.tgattr::text = '' AND t.tgqual IS NULL)) AS cacheable
SQL;

        $proof = $connection->selectOne($sql, [$tableNames, 'public.'.self::ROW_FUNCTION.'()', 'public.'.self::TRUNCATE_FUNCTION.'()', 'public.'.self::WITNESS_FUNCTION.'(oid,text)',
            self::ROW_TRIGGER, self::TRUNCATE_TRIGGER, $body, 'public.'.self::ROW_FUNCTION.'()', $body, 'public.'.self::WITNESS_FUNCTION.'(oid,text)', self::WITNESS_BODY, 'public.'.self::TRUNCATE_FUNCTION.'()', self::TRUNCATE_BODY,
            self::ROW_TRIGGER, 'public.'.self::ROW_FUNCTION.'()', self::TRUNCATE_TRIGGER, 'public.'.self::TRUNCATE_FUNCTION.'()']);
        if ($proof === null) {
            throw new LogicException('assistant_snapshot_scoped_proof_unavailable');
        }

        return $proof;
    }
}
