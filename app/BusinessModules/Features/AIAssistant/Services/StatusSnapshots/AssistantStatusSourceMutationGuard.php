<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

final class AssistantStatusSourceMutationGuard
{
    public const TABLE = 'ai_rag_sources';

    public const ROW_TRIGGER = 'assistant_source_snapshot_semantic_mutation';

    public const ROW_FUNCTION = 'track_assistant_source_snapshot_semantic_mutation';

    public const BODY_PREFIX = "\nBEGIN\n    IF TG_TABLE_SCHEMA <> 'public' OR TG_TABLE_NAME <> 'ai_rag_sources' OR TG_OP <> 'UPDATE' OR TG_LEVEL <> 'ROW' THEN\n        RAISE EXCEPTION 'assistant_source_snapshot_guard_scope';\n    END IF;\n    IF ROW(";

    public const BODY_SEPARATOR = ') IS DISTINCT FROM ROW(';

    public const BODY_SUFFIX = ") THEN\n        INSERT INTO public.ai_assistant_status_snapshot_changes (xid, relation_oid) VALUES (pg_current_xact_id(), TG_RELID) ON CONFLICT (xid, relation_oid) DO NOTHING;\n    END IF;\n    RETURN NULL;\nEND;\n";

    public static function quoteColumn(string $column): string
    {
        return '"'.str_replace('"', '""', $column).'"';
    }

    public static function body(array $columns): string
    {
        return self::BODY_PREFIX
            .implode(', ', array_map(static fn (string $column): string => 'OLD.'.self::quoteColumn($column), $columns))
            .self::BODY_SEPARATOR
            .implode(', ', array_map(static fn (string $column): string => 'NEW.'.self::quoteColumn($column), $columns))
            .self::BODY_SUFFIX;
    }
}
