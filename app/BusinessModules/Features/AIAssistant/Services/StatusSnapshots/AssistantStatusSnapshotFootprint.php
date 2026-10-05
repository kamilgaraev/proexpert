<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

final class AssistantStatusSnapshotFootprint
{
    public function capture(callable $operation): array
    {
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        if ($dispatcher === null) { return ['value' => $operation(), 'relations' => null]; }
        $queries = [];
        $observer = static function (QueryExecuted $query) use (&$queries, $connection): void {
            $queries[] = [$query->sql, $query->bindings, $query->connection === $connection];
        };
        $beforeConnections = array_keys(DB::getConnections());
        $dispatchers = [];
        foreach (DB::getConnections() as $observedConnection) {
            $original = $observedConnection->getEventDispatcher();
            $dispatchers[] = [$observedConnection, $original];
            $capturing = $original === null ? new \Illuminate\Events\Dispatcher : clone $original;
            $capturing->listen(QueryExecuted::class, $observer);
            $observedConnection->setEventDispatcher($capturing);
        }
        try {
            $value = $operation();
        } finally {
            foreach ($dispatchers as [$observedConnection, $original]) {
                if ($original === null) { $observedConnection->unsetEventDispatcher(); }
                else { $observedConnection->setEventDispatcher($original); }
            }
        }
        if (array_keys(DB::getConnections()) !== $beforeConnections) { return ['value' => $value, 'relations' => null, 'reason' => 'connection_set_changed']; }
        $functions = [];
        foreach ($connection->select("SELECT proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname <> 'pg_catalog'") as $function) {
            $functions[$function->proname] = true;
        }
        $relations = [];
        $catalogNames = [];
        $catalogSchemas = [];
        foreach ($connection->select("SELECT n.nspname AS schema_name, c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f')") as $relation) {
            $catalogNames[$relation->relname] = true;
            $catalogSchemas[$relation->schema_name][$relation->relname] = true;
        }
        $resolvedNames = [];
        $seen = [];
        foreach ($queries as [$sql, $bindings, $sameConnection]) {
            if ($sameConnection && preg_match("/^SET LOCAL (?:statement_timeout = [0-9]+|jit = off|work_mem = '16MB')$/iD", $sql) === 1) { continue; }
            if (! $sameConnection) { return ['value' => $value, 'relations' => null, 'reason' => 'unsupported_connection']; }
            if (preg_match('/^\s*(?:\(\s*)*(?:select|with)\b/i', $sql) !== 1) {
                preg_match('/^\s*([a-z_]+)/i', $sql, $verb);

                return ['value' => $value, 'relations' => null, 'reason' => 'unsupported_statement:'.strtolower($verb[1] ?? 'unknown')];
            }
            $key = hash('sha256', serialize([$sql, $bindings]));
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            if ($sql === "SELECT current_setting('statement_timeout') AS timeout, current_setting('jit') AS jit, current_setting('work_mem') AS work_mem, (SELECT setting::int FROM pg_settings WHERE name = 'work_mem') AS work_mem_kb"
                || $sql === "SELECT set_config('statement_timeout', ?, true), set_config('jit', ?, true), set_config('work_mem', ?, true)") { continue; }
            $syntax = preg_replace("/'(?:''|[^'])*'/s", "''", $sql);
            if (preg_match('/\$[A-Za-z_0-9]*\$|--|\/\*|\bU&/i', $syntax ?? '') === 1 || preg_match("/\\bE'/i", $sql) === 1) { return ['value' => $value, 'relations' => null, 'reason' => 'unsupported_sql_literal']; }
            $identifier = '(?:"(?:[^"]|"")*"|[A-Za-z_][A-Za-z_0-9]*)';
            $syntax = preg_replace('/\bAS\s+('.$identifier.')\s*\(\s*'.$identifier.'(?:\s*,\s*'.$identifier.')*\s*\)/i', 'AS $1', $syntax ?? '');
            preg_match_all('/(?<![A-Za-z_0-9."])(?:(?<schema>'.$identifier.')\s*\.\s*)?(?<name>'.$identifier.')\s*\(/', $syntax ?? '', $matches, PREG_SET_ORDER);
            $grammar = ['from', 'join', 'where', 'select', 'on', 'having', 'union', 'array', 'distinct', 'case', 'when', 'then', 'else',
                'in', 'any', 'all', 'and', 'or', 'not', 'exists', 'as', 'materialized', 'values', 'filter', 'over', 'cast', 'numeric', 'decimal', 'varchar', 'character', 'timestamp', 'timestamptz'];
            $catalogFunctions = ['count', 'sum', 'min', 'max', 'array_agg', 'coalesce', 'nullif', 'btrim', 'trim', 'lower', 'upper', 'length', 'regexp_replace',
                'left', 'right', 'replace', 'strpos', 'split_part', 'substring', 'substr', 'concat', 'concat_ws', 'abs', 'round', 'ceil', 'ceiling', 'floor',
                'greatest', 'least', 'cardinality', 'array_length', 'array_cat', 'array_remove', 'array_append', 'bool_and', 'bool_or',
                'jsonb_build_array', 'jsonb_build_object', 'jsonb_object_agg', 'string_agg', 'jsonb_agg',
                'jsonb_array_elements', 'jsonb_array_elements_text', 'jsonb_array_length', 'jsonb_typeof', 'jsonb_extract_path', 'jsonb_extract_path_text',
                'pg_input_is_valid', 'now', 'format_type', 'pg_get_expr', 'pg_table_is_visible', 'pg_type_is_visible', 'col_description', 'obj_description', 'pg_get_serial_sequence'];
            foreach ($matches as $match) {
                $schema = $this->identifier($match['schema'] ?? '');
                $name = $this->identifier($match['name']);
                if ($name === 'pg_input_is_valid') {
                    $inputCalls = preg_match_all('/\bpg_input_is_valid\s*\(/i', $sql);
                    if ($inputCalls === 0 || $inputCalls !== preg_match_all("/\\bpg_input_is_valid\\s*\\([^,]*,\\s*'(?:bigint|integer|uuid|numeric)'\\s*\\)/i", $sql)) { return ['value' => $value, 'relations' => null, 'reason' => 'unsupported_input_type']; }
                }
                if ($schema === '' && ! str_starts_with($match['name'], '"') && in_array($name, $grammar, true) && ! isset($functions[$name])) { continue; }
                if (! in_array($schema, ['', 'pg_catalog'], true) || ! in_array($name, $catalogFunctions, true)
                    || $schema === '' && isset($functions[$name])) { return ['value' => $value, 'relations' => null, 'reason' => 'unsupported_function:'.$name]; }
            }
            preg_match_all('/\b(?:from|join)\s+(?<relation>'.$identifier.'(?:\s*\.\s*'.$identifier.')?)/i', $syntax ?? '', $relationMatches, PREG_SET_ORDER);
            foreach ($relationMatches as $match) {
                $name = preg_replace('/\s*\.\s*/', '.', $match['relation']);
                if (isset($resolvedNames[$name])) { continue; }
                $resolvedNames[$name] = true;
                preg_match_all('/"((?:[^"]|"")*)"|([A-Za-z_][A-Za-z_0-9]*)/', $name, $parts, PREG_SET_ORDER);
                $parts = array_map(static fn (array $part): string => ($part[1] ?? '') !== '' ? str_replace('""', '"', $part[1]) : strtolower($part[2]), $parts);
                if (count($parts) === 1 && ! isset($catalogNames[$parts[0]])
                    || count($parts) === 2 && ! isset($catalogSchemas[$parts[0]][$parts[1]])) { continue; }
                $relation = $connection->selectOne('SELECT n.nspname AS schema_name, c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = to_regclass(?)', [$name]);
                if ($relation === null) { continue; }
                if ($relation->schema_name === 'public') { $relations['public.'.$relation->relname] = 'public.'.$relation->relname; }
                elseif (! in_array($relation->schema_name, ['pg_catalog', 'information_schema'], true)) { return ['value' => $value, 'relations' => null, 'reason' => 'unsupported_schema']; }
            }
            try {
                $row = $connection->selectOne('EXPLAIN (FORMAT JSON, VERBOSE) '.$sql, $bindings);
                $plan = json_decode((string) ((array) $row)['QUERY PLAN'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $exception) {
                return ['value' => $value, 'relations' => null, 'reason' => 'plan_unavailable:'.get_class($exception)];
            }
            if (! $this->collectRelations($plan, $relations)) { return ['value' => $value, 'relations' => null]; }
        }
        sort($relations);

        return ['value' => $value, 'relations' => $relations === [] ? null : array_values($relations)];
    }

    private function collectRelations(array $node, array &$relations): bool
    {
        if (isset($node['Relation Name'])) {
            $schema = $node['Schema'] ?? null;
            if ($schema === 'public') {
                $name = $node['Relation Name'];
                if (! is_string($name) || preg_match('/^[a-z_][a-z0-9_]*$/D', $name) !== 1) { return false; }
                $relations['public.'.$name] = 'public.'.$name;
            } elseif (! in_array($schema, ['pg_catalog', 'information_schema'], true)) { return false; }
        }
        foreach ($node as $value) {
            if (is_array($value) && ! $this->collectRelations($value, $relations)) { return false; }
        }

        return true;
    }

    private function identifier(string $value): string
    {
        return str_starts_with($value, '"') ? str_replace('""', '"', substr($value, 1, -1)) : strtolower($value);
    }
}
