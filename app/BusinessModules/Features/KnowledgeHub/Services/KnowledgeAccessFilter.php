<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class KnowledgeAccessFilter
{
    /**
     * @var array<string, bool>
     */
    private array $columnExists = [];

    public function apply(Builder $query, KnowledgeAccessContext $context): Builder
    {
        $this->whereJsonEmptyOrContainsAny($query, 'surfaces', [$context->surface->value]);
        $this->whereJsonEmptyOrContainsAny($query, 'audiences', $context->audiences);
        $this->whereJsonEmptyOrContainsAny($query, 'permission_keys', $context->permissionKeys);

        if ($context->moduleSlugs !== []) {
            $this->whereJsonEmptyOrContainsAny($query, 'module_slugs', $context->moduleSlugs);
        } else {
            $this->whereJsonEmpty($query, 'module_slugs');
        }

        if ($context->contextKey !== null) {
            $this->whereJsonEmptyOrContainsAny($query, 'context_keys', [$context->contextKey]);
        }

        return $query;
    }

    /**
     * @param list<string> $values
     */
    private function whereJsonEmptyOrContainsAny(Builder $query, string $column, array $values): void
    {
        if (! $this->hasColumn($column)) {
            return;
        }

        $values = collect($values)
            ->filter(fn (string $value): bool => trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        $query->where(function (Builder $builder) use ($column, $values): void {
            $builder->whereNull($column)
                ->orWhereJsonLength($column, 0);

            foreach ($values as $value) {
                $builder->orWhereJsonContains($column, $value);
            }
        });
    }

    private function whereJsonEmpty(Builder $query, string $column): void
    {
        if (! $this->hasColumn($column)) {
            return;
        }

        $query->where(function (Builder $builder) use ($column): void {
            $builder->whereNull($column)
                ->orWhereJsonLength($column, 0);
        });
    }

    private function hasColumn(string $column): bool
    {
        if (! array_key_exists($column, $this->columnExists)) {
            $this->columnExists[$column] = Schema::hasColumn('knowledge_articles', $column);
        }

        return $this->columnExists[$column];
    }
}
