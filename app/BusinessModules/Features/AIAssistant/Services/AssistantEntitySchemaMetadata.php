<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\PostgresBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AssistantEntitySchemaMetadata
{
    private array $columns = [];

    private bool $schemaMetadataPrefetched = false;

    public function columns(string $table, ?AssistantAclQueryCompiler $compiler = null): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $load = static fn (): array => Schema::getColumnListing($table);
        $columns = $compiler === null ? $load() : $compiler->remember('schema:'.$table, $load);
        if ($columns !== []) {
            $this->columns[$table] = $columns;
        }

        return $columns;
    }

    public function prefetchForEntities(array $types, ?callable $checkpoint = null): void
    {
        $definitions = AssistantEntityAccessCatalog::entityDefinitions();
        $parents = AssistantEntityAccessCatalog::parentColumns();
        $intrinsicParents = AssistantEntityAccessCatalog::intrinsicParents();
        $queue = [];
        $seen = [];
        $enqueue = static function (mixed $type) use (&$queue, &$seen, $definitions): void {
            if (! is_string($type) || ! isset($definitions[$type]) || isset($seen[$type]) || count($queue) >= 32) {
                return;
            }
            $seen[$type] = true;
            $queue[] = $type;
        };
        foreach ($types as $type) {
            $enqueue($type);
        }
        $selected = [];
        for ($i = 0; $i < count($queue); $i++) {
            $type = $queue[$i];
            $selected[$type] = $definitions[$type];
            $enqueue($intrinsicParents[$type][1] ?? null);
            foreach ($parents[$type] ?? [] as $parent) {
                $enqueue($parent['type'] ?? null);
            }
            if ($type === 'estimate') {
                $enqueue('contract');
            }
            if ($type === 'payment_document') {
                foreach (['contract', 'estimate', 'performance_act', 'completed_work'] as $parent) {
                    $enqueue($parent);
                }
            }
        }
        if ($selected !== []) {
            $this->prefetch($selected, $checkpoint, cacheMissing: false);
        }
    }

    public function prefetch(array $definitions, ?callable $checkpoint = null, ?callable $checkDeadline = null, bool $cacheMissing = true): void
    {
        if ($this->schemaMetadataPrefetched || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        if ($checkDeadline !== null) {
            $checkDeadline();
        }

        $connection = DB::connection();
        $schemaBuilder = $connection->getSchemaBuilder();
        if (! $schemaBuilder instanceof PostgresBuilder) {
            return;
        }

        $grammar = $connection->getSchemaGrammar();
        $pairTables = [];
        foreach ($definitions as $definition) {
            $modelClass = $definition[1] ?? null;
            if (! is_string($modelClass) || ! class_exists($modelClass)) {
                continue;
            }

            $model = (new \ReflectionClass($modelClass))->newInstanceWithoutConstructor();
            if (! $model instanceof Model) {
                continue;
            }
            $table = $model->getTable();
            if (! $cacheMissing && isset($this->columns[$table])) {
                continue;
            }
            [$schema, $relation] = $this->schemaMetadataTableReference($schemaBuilder, $connection, $table);
            $grammar->compileColumns($schema, $relation);
            $pairTables[$schema."\0".$relation][$table] = true;
        }

        $values = [];
        $bindings = [];
        foreach ($pairTables as $key => $_tables) {
            [$schema, $relation] = explode("\0", $key, 2);
            $values[] = '(?, ?)';
            $bindings[] = $schema;
            $bindings[] = $relation;
        }

        $columnsByPair = [];
        if ($values !== []) {
            if ($checkpoint !== null) {
                $checkpoint();
            }
            $sql = 'select n.nspname as schema_name, c.relname as table_name, a.attname as column_name, a.attnum as ordinal_position '
                .'from pg_attribute a join pg_class c on c.oid = a.attrelid join pg_type t on t.oid = a.atttypid '
                .'join pg_namespace n on n.oid = c.relnamespace where a.attnum > 0 '
                .'and exists (select 1 from (values '.implode(',', $values).') as target(schema_name, relation_name) '
                .'where target.schema_name::text = n.nspname::text and target.relation_name::text = c.relname::text) '
                .'order by n.nspname, c.relname, a.attnum';

            foreach ($connection->selectFromWriteConnection($sql, $bindings) as $row) {
                $key = $row->schema_name."\0".$row->table_name;
                $columnsByPair[$key][] = (string) $row->column_name;
            }
        }
        if ($checkDeadline !== null) {
            $checkDeadline();
        }

        $columns = [];
        foreach ($pairTables as $key => $tables) {
            $tableColumns = $columnsByPair[$key] ?? [];
            if (! $cacheMissing && $tableColumns === []) {
                continue;
            }
            foreach (array_keys($tables) as $table) {
                $columns[$table] = $tableColumns;
            }
        }
        $this->columns = array_replace($this->columns, $columns);
        $this->schemaMetadataPrefetched = $cacheMissing;
    }

    private function schemaMetadataTableReference(PostgresBuilder $schemaBuilder, \Illuminate\Database\Connection $connection, string $table): array
    {
        [$schema, $relation] = $schemaBuilder->parseSchemaAndTable($table);

        return [$schema, $connection->getTablePrefix().$relation];
    }
}
