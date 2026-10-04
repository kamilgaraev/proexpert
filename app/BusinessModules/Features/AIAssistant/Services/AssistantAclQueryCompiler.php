<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class AssistantAclQueryCompiler
{
    private array $queries = [];
    private array $references = [];
    private array $dependencies = [];
    private array $heights = [];
    private array $decisions = [];

    public function remember(string $key, callable $resolve): mixed
    {
        if (! array_key_exists($key, $this->decisions)) { $this->decisions[$key] = $resolve(); }

        return $this->decisions[$key];
    }

    public function __construct(private readonly int $actorId, private readonly int $organizationId, private readonly bool $compact = false, private readonly array $inlineTypes = []) {}

    public function accepts(int $actorId, int $organizationId): bool
    {
        return $this->actorId === $actorId && $this->organizationId === $organizationId;
    }

    public function has(string $type): bool
    {
        return isset($this->references[$type]);
    }

    public function reference(string $type, array $ancestors = []): ?Builder
    {
        if (! $this->has($type) || in_array($type, $ancestors, true) || count($ancestors) + $this->heights[$type] > 16
            || array_intersect($ancestors, $this->dependencies[$type] ?? []) !== []) { return null; }
        if ($ancestors !== []) { $this->dependencies[$ancestors[array_key_last($ancestors)]][$type] = $type; }

        return clone $this->references[$type];
    }

    public function register(string $type, Builder $query, array $internalColumns, array $ancestors = [], bool $materialize = true): Builder
    {
        $materialize = $materialize && ! in_array($type, $this->inlineTypes, true);
        $model = $query->getModel();
        $table = $model->getTable();
        $name = 'assistant_acl_'.count($this->queries);
        $selected = $query->getQuery()->columns;
        $selectBindings = $query->getQuery()->getRawBindings()['select'];
        $materialized = clone $query;
        if ($this->compact && $internalColumns !== [] && $selectBindings === []) {
            $selected = array_map(static fn (string $column): string => $table.'.'.$column, $internalColumns);
            $materialized->select($selected);
        }
        if ($selected !== null) {
            foreach ($internalColumns as $column) {
                $materialized->addSelect($table.'.'.$column);
            }
        }
        $base = $materialized->toBase();
        $this->queries[] = ['name' => $name, 'sql' => $base->toSql(), 'bindings' => $base->getBindings(), 'materialize' => $materialize,
            'query' => $base, 'table' => $table, 'compactColumns' => $this->compact && $internalColumns !== [] && $selectBindings === [] ? $internalColumns : []];
        $reference = $model->newQueryWithoutScopes()->from($name.' as '.$table)->select($selected ?? [$table.'.*']);
        $reference->getQuery()->setBindings($selectBindings, 'select');
        $this->references[$type] = $reference;
        $this->heights[$type] = 1;
        foreach ($this->dependencies[$type] ?? [] as $dependency) {
            $this->heights[$type] = max($this->heights[$type], 1 + $this->heights[$dependency]);
        }
        if (count($ancestors) > 1) { $this->dependencies[$ancestors[count($ancestors) - 2]][$type] = $type; }

        return clone $reference;
    }

    public function finish(Builder $query): Builder
    {
        if ($this->queries === []) {
            return $query;
        }
        $base = $query->toBase();
        $selected = $base->columns;
        $selectBindings = $base->getRawBindings()['select'];
        $base = clone $base;
        $base->select($query->getModel()->getTable().'.*');
        $sql = $base->toSql();
        preg_match_all('/\bassistant_acl_\d+\b/', $sql, $matches);
        $required = array_fill_keys($matches[0], true);
        foreach (array_reverse($this->queries) as $definition) {
            if (! isset($required[$definition['name']])) {
                continue;
            }
            preg_match_all('/\bassistant_acl_\d+\b/', $definition['sql'], $dependencies);
            foreach ($dependencies[0] as $dependency) {
                $required[$dependency] = true;
            }
        }
        $parts = [];
        $bindings = [];
        $referencedColumns = [];
        if ($this->compact) {
            $fragments = [$sql];
            foreach ($this->queries as $definition) {
                if (! isset($required[$definition['name']])) { continue; }
                $fragments[] = $definition['compactColumns'] === [] ? $definition['sql']
                    : $definition['query']->cloneWithout(['columns'])->cloneWithoutBindings(['select'])->selectRaw('1')->toSql();
            }
            foreach ($fragments as $fragment) {
                preg_match_all('/(?:"([a-zA-Z_][a-zA-Z_0-9]*)"|([a-zA-Z_][a-zA-Z_0-9]*))\.(?:"([a-zA-Z_][a-zA-Z_0-9]*)"|([a-zA-Z_][a-zA-Z_0-9]*))/', $fragment, $columns, PREG_SET_ORDER);
                foreach ($columns as $column) {
                    $referencedColumns[$column[1] ?: $column[2]][$column[3] ?: $column[4]] = true;
                }
            }
        }
        foreach ($this->queries as $definition) {
            if (! isset($required[$definition['name']])) {
                continue;
            }
            if ($definition['compactColumns'] !== []) {
                $table = $definition['table'];
                $columns = array_unique([...$definition['compactColumns'], ...array_keys($referencedColumns[$table] ?? [])]);
                $definition['sql'] = (clone $definition['query'])->select(array_map(static fn (string $column): string => $table.'.'.$column, $columns))->toSql();
            }
            $parts[] = '"'.$definition['name'].'" AS '.($definition['materialize'] ? 'MATERIALIZED' : 'NOT MATERIALIZED').' ('.$definition['sql'].')';
            array_push($bindings, ...$definition['bindings']);
        }
        array_push($bindings, ...$base->getBindings());
        $model = $query->getModel();

        $finished = $model->newQueryWithoutScopes()
            ->fromRaw('('.($parts === [] ? '' : 'WITH '.implode(', ', $parts).' ').$sql.') as "'.$model->getTable().'"', $bindings)
            ->select($selected ?? [$model->getTable().'.*'])
            ->setEagerLoads($query->getEagerLoads());
        $finished->getQuery()->setBindings($selectBindings, 'select');
        $finished->whereExists(\App\Models\User::query()->whereKey($this->actorId)->where('is_active', true)
            ->where('current_organization_id', $this->organizationId)->select('users.id')->toBase());
        $finished->whereExists(function (QueryBuilder $membership): void {
            $membership->selectRaw('1')->from('organization_user')->where('user_id', $this->actorId)
                ->where('organization_id', $this->organizationId)->where('is_active', true);
        });

        return $finished;
    }
}
