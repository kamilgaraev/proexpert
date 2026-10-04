<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class SalesBusinessRagSource implements RagSourceCollectorInterface
{
    public function enabled(): bool
    {
        return true;
    }

    public function entities(): array
    {
        return array_filter(AssistantSalesBusinessMetadata::inventory(), fn (array $definition, string $type): bool => AssistantSalesBusinessMetadata::recordDefinitions()[$type]['source'] === $this->sourceType(), ARRAY_FILTER_USE_BOTH);
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        if (! $this->enabled()) { return []; }
        foreach ($this->entities() as $type => $definition) {
            $query = $this->collectionQuery($type, $organizationId, $projectId);
            $batchSize = array_intersect($definition['fields'], ['payload', 'source_snapshot', 'source_manifest', 'totals', 'preview_summary']) === [] ? 50 : 1;
            foreach ($query->lazyById($batchSize) as $model) {
                yield $this->chunk($model, $type, $definition['fields'], $organizationId);
            }
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if (! $this->enabled()) { return []; }
        $definition = $this->entities()[$entityType] ?? null;
        if ($definition === null || $organizationId < 1) {
            return [];
        }
        $model = $this->collectionQuery($entityType, $organizationId)->whereKey($entityId)->first();

        return $model === null ? [] : [$this->chunk($model, $entityType, $definition['fields'], $organizationId)];
    }

    public static function scopedQuery(string $type, int $organizationId, ?int $projectId = null, bool $skipSelf = false, array $seen = []): Builder
    {
        $scopes = in_array($type, ['purchase_receipt_return', 'supply_lifecycle_event', 'procurement_process_event'], true) && $seen === [] ? [] : null;
        $query = self::buildScopedQuery($type, $organizationId, $projectId, $skipSelf, $seen, $scopes);
        if ($scopes === null || $scopes === []) {
            return $query;
        }
        $table = $query->getModel()->getTable();
        $definitions = [];
        $bindings = [];
        foreach ($scopes as $scope) {
            $definitions[] = $scope['name'].' AS MATERIALIZED ('.$scope['query']->toSql().')';
            array_push($bindings, ...$scope['query']->getBindings());
        }
        $query->select($table.'.'.$query->getModel()->getKeyName());
        array_push($bindings, ...$query->getBindings());
        $ids = DB::query()->select($query->getModel()->getKeyName())
            ->fromRaw('(WITH '.implode(', ', $definitions).' '.$query->toSql().') AS rag_valid_entities', $bindings);

        return $query->getModel()->newQuery()->whereIn($table.'.'.$query->getModel()->getKeyName(), $ids);
    }

    private static function buildScopedQuery(string $type, int $organizationId, ?int $projectId, bool $skipSelf, array $seen, ?array &$scopes): Builder
    {
        $definition = AssistantSalesBusinessMetadata::scopeDefinitions()[$type] ?? null;
        if ($definition === null) {
            throw new RuntimeException('assistant_domain_entity_unsupported');
        }
        $class = $definition['model'];
        $query = $class::query();
        $table = $query->getModel()->getTable();
        if ($organizationId < 1 || count($seen) > 16 || in_array($type, $seen, true)) {
            return $query->whereRaw('1 = 0');
        }
        $organizationColumn = $definition['organization_column'];
        if ($type === 'marketplace_contractor_profile') {
            self::applyMarketplaceNetwork($query, $organizationId);
        } elseif ($type === 'marketplace_hiring_offer') {
            $query->where(static fn (Builder $scope) => $scope->where($table.'.hiring_organization_id', $organizationId)->orWhere($table.'.contractor_organization_id', $organizationId));
        } elseif ($organizationColumn !== null) {
            if (isset(AssistantSalesBusinessMetadata::organizationNullableCatalogs()[$type])) {
                $query->where(static fn (Builder $scope) => $scope->where($table.'.'.$organizationColumn, $organizationId)->orWhereNull($table.'.'.$organizationColumn));
            } else { $query->where($table.'.'.$organizationColumn, $organizationId); }
        } elseif (empty($definition['parents']) && !isset(AssistantSalesBusinessMetadata::globalCatalogEntities()[$type])) {
            return $query->whereRaw('1 = 0');
        }
        foreach (AssistantSalesBusinessMetadata::rowPredicates()[$type] ?? [] as $column => $value) { $query->where($table.'.'.$column, $value); }
        if ($type === 'crm_timeline_event' || $type === 'crm_merge_event') {
            $types = $type === 'crm_merge_event' ? ['companies' => 'crm_company', 'contacts' => 'crm_contact'] : ['companies' => 'crm_company', 'contacts' => 'crm_contact', 'leads' => 'crm_lead', 'deals' => 'crm_deal', 'activities' => 'crm_activity'];
            $column = $type === 'crm_merge_event' ? 'master_id' : 'entity_id';
            $query->where(static function (Builder $scope) use ($types, $column, $organizationId, $table, $seen, $type): void {
                $scope->whereRaw('1 = 0');
                foreach ($types as $nativeType => $parentType) {
                    $parent = self::scopedQuery($parentType, $organizationId, null, false, [...$seen, $type]);
                    $parentTable = $parent->getModel()->getTable();
                    $scope->orWhere(static fn (Builder $branch): Builder => $branch->where($table.'.entity_type', $nativeType)->whereIn($table.'.'.$column, $parent->select($parentTable.'.id')));
                }
            });
        }
        if ($type === 'crm_contact_identity' || $type === 'crm_contact_point') {
            $query->where(static fn (Builder $scope): Builder => $scope->whereNotNull($table.'.company_id')->orWhereNotNull($table.'.contact_id'));
        }
        $hasProject = in_array('project_id', $definition['fields'], true);
        if ($hasProject) {
            $projectQuery = self::projectQuery($organizationId);
            $projectQuery->whereColumn('projects.id', $table.'.project_id')->selectRaw('1');
            $query->where(static fn (Builder $scope) => $scope->whereNull($table.'.project_id')->orWhereExists($projectQuery->toBase()));
            if ($projectId !== null) {
                $query->where($table.'.project_id', $projectId);
            }
        }
        $inheritedProject = false;
        foreach (AssistantSalesBusinessMetadata::scopeDefinitions()[$type]['parents'] ?? [] as $column => $parent) {
            if ($skipSelf && ($parent['reference_only'] ?? false)) { continue; }
            if ($parent['type'] === 'project') {
                continue;
            }
            $parentDefinition = AssistantSalesBusinessMetadata::scopeDefinitions()[$parent['type']] ?? null;
            if ($parentDefinition !== null) {
                $propagateProject = ! $hasProject && self::hasProjectLineage($parent['type']);
                $parentQuery = self::parentScopeQuery($parent['type'], $organizationId, $propagateProject ? $projectId : null, ($parent['reference_only'] ?? false) && $parent['type'] === $type, ($parent['reference_only'] ?? false) && $parent['type'] === $type ? $seen : [...$seen, $type], $scopes);
                $inheritedProject = $inheritedProject || $propagateProject;
            } else { throw new RuntimeException('assistant_sales_parent_unsupported'); }
            $parentKey = $parent['key'] ?? 'id';
            $parentTable = $parentQuery->getModel()->getTable();
            foreach ($parent['matches'] ?? [] as $parentColumn => $childColumn) {
                $parentQuery->whereColumn($parentTable.'.'.$parentColumn, $table.'.'.$childColumn);
            }
            if ($parentTable === $table) {
                $parentQuery->select($parentTable.'.'.$parentKey);
                $query->where(static function (Builder $scope) use ($parent, $column, $table, $parentQuery): void {
                    if ($parent['nullable']) {
                        $scope->whereNull($table.'.'.$column)->orWhereIn($table.'.'.$column, $parentQuery);
                    } else {
                        $scope->whereIn($table.'.'.$column, $parentQuery);
                    }
                });
                continue;
            }
            $parentQuery->whereColumn($parentTable.'.'.$parentKey, $table.'.'.$column)->selectRaw('1');
            $query->where(static function (Builder $scope) use ($parent, $column, $table, $parentQuery): void {
                if ($parent['nullable']) {
                    $scope->whereNull($table.'.'.$column)->orWhereExists($parentQuery->toBase());
                } else {
                    $scope->whereExists($parentQuery->toBase());
                }
            });
        }
        if ($projectId !== null && ! $hasProject && ! $inheritedProject) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private static function parentScopeQuery(string $type, int $organizationId, ?int $projectId, bool $skipSelf, array $seen, ?array &$scopes): Builder
    {
        if ($scopes === null || in_array($type, $seen, true) || count($seen) > 16) {
            return self::buildScopedQuery($type, $organizationId, $projectId, $skipSelf, $seen, $scopes);
        }
        $key = $type.':'.($projectId ?? 'all').':'.(int) $skipSelf;
        if (! isset($scopes[$key])) {
            $query = self::buildScopedQuery($type, $organizationId, $projectId, $skipSelf, $seen, $scopes);
            $table = $query->getModel()->getTable();
            $columns = [$query->getModel()->getKeyName()];
            foreach (AssistantSalesBusinessMetadata::scopeDefinitions() as $definition) {
                foreach ($definition['parents'] ?? [] as $parent) {
                    if ($parent['type'] === $type) {
                        $columns[] = $parent['key'] ?? 'id';
                        array_push($columns, ...array_keys($parent['matches'] ?? []));
                    }
                }
            }
            $query->select(array_map(static fn (string $column): string => $table.'.'.$column, array_unique($columns)));
            $scopes[$key] = ['name' => 'rag_parent_'.count($scopes), 'query' => $query];
        }
        $model = AssistantSalesBusinessMetadata::scopeDefinitions()[$type]['model'];
        $query = $model::query()->withoutGlobalScopes();

        return $query->from($scopes[$key]['name'].' as '.$query->getModel()->getTable());
    }

    protected function chunk(Model $model, string $type, array $fields, int $organizationId): RagChunkData
    {
        $attributes = array_intersect_key($model->getAttributes(), array_flip($fields));
        foreach (AssistantSalesBusinessMetadata::numericFields() as $field) {
            if (array_key_exists($field, $attributes)) {
                $raw = $model->getRawOriginal($field);
                if ($raw !== null && ((! is_string($raw) && ! is_int($raw)) || ! preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $raw))) {
                    unset($attributes[$field]);
                } else {
                    $attributes[$field] = $raw;
                }
            }
        }
        $projection = clone $model;
        $projection->setRawAttributes($attributes, true);
        $data = array_intersect_key($projection->attributesToArray(), array_flip($fields));
        foreach (AssistantSalesBusinessMetadata::numericFields() as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $attributes[$field];
            }
        }
        $title = (string) ($data['title'] ?? $data['name'] ?? $data['original_name'] ?? $data['code'] ?? $data['number'] ?? $model->getKey());
        $updatedAt = $model->getAttribute('updated_at') ?? $model->getAttribute('generated_at') ?? $model->getAttribute('recorded_at') ?? $model->getAttribute('created_at');
        if (is_string($updatedAt) && $updatedAt !== '') {
            $updatedAt = Carbon::parse($updatedAt);
        }

        $projectId = $model->getAttribute('project_id') ?? $model->getAttribute('assistant_project_id');

        return new RagChunkData($organizationId, is_numeric($projectId) ? (int) $projectId : null,
            $this->sourceType(), $type, $model->getKey(), $title,
            $title."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $data,
            $updatedAt instanceof DateTimeInterface ? $updatedAt : null);
    }

    private static function hasProjectLineage(string $type, array $seen = []): bool
    {
        if (in_array($type, $seen, true)) { return false; }
        if (in_array('project_id', AssistantSalesBusinessMetadata::scopeDefinitions()[$type]['fields'] ?? [], true)) {
            return true;
        }
        foreach (AssistantSalesBusinessMetadata::scopeDefinitions()[$type]['parents'] ?? [] as $parent) {
            if (isset(AssistantSalesBusinessMetadata::scopeDefinitions()[$parent['type']]) && self::hasProjectLineage($parent['type'], [...$seen, $type])) {
                return true;
            }
        }

        return false;
    }

    protected function collectionQuery(string $type, int $organizationId, ?int $projectId = null): Builder
    {
        $query = self::scopedQuery($type, $organizationId, $projectId);
        $table = $query->getModel()->getTable();
        $query->select(array_map(static fn (string $column): string => $table.'.'.$column, AssistantSalesBusinessMetadata::safeSelectColumns()[$type]));
        if (! in_array('project_id', AssistantSalesBusinessMetadata::scopeDefinitions()[$type]['fields'], true)) {
            $table = $query->getModel()->getTable();
            foreach (AssistantSalesBusinessMetadata::scopeDefinitions()[$type]['parents'] ?? [] as $column => $parent) {
                if (! isset(AssistantSalesBusinessMetadata::scopeDefinitions()[$parent['type']]) || ! self::hasProjectLineage($parent['type'])) {
                    continue;
                }
                $projection = self::projectProjection($parent['type'], $organizationId, in_array($type, ['purchase_receipt_return', 'commercial_proposal_line_item', 'commercial_proposal_approval'], true));
                $parentTable = $projection->getModel()->getTable();
                $projection->whereColumn($parentTable.'.'.($parent['key'] ?? 'id'), $table.'.'.$column)->limit(1);
                $query->addSelect(['assistant_project_id' => $projection]);
                break;
            }
        }

        return $query;
    }

    private static function projectProjection(string $type, int $organizationId, bool $validatedLineage = false): Builder
    {
        $definition = AssistantSalesBusinessMetadata::scopeDefinitions()[$type];
        $query = $validatedLineage ? $definition['model']::query() : self::scopedQuery($type, $organizationId);
        $table = $query->getModel()->getTable();
        if (in_array('project_id', AssistantSalesBusinessMetadata::scopeDefinitions()[$type]['fields'], true)) {
            return $query->select($table.'.project_id');
        }
        $projections = [];
        $bindings = [];
        foreach (AssistantSalesBusinessMetadata::scopeDefinitions()[$type]['parents'] ?? [] as $column => $parent) {
            if (! isset(AssistantSalesBusinessMetadata::scopeDefinitions()[$parent['type']]) || ! self::hasProjectLineage($parent['type'])) {
                continue;
            }
            $projection = self::projectProjection($parent['type'], $organizationId, $validatedLineage);
            $parentTable = $projection->getModel()->getTable();
            $projection->whereColumn($parentTable.'.'.($parent['key'] ?? 'id'), $table.'.'.$column)->limit(1);
            $projections[] = '('.$projection->toSql().')';
            array_push($bindings, ...$projection->getBindings());
        }

        return $projections === []
            ? $query->selectRaw('NULL')->whereRaw('1 = 0')
            : $query->selectRaw('COALESCE('.implode(', ', $projections).') AS derived_project_id', $bindings);
    }

    public static function applyMarketplaceNetwork(Builder $query, int $organizationId): void
    {
        $table = $query->getModel()->getTable();
        $network = app(\App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceSearchService::class)->networkOrganizationIds($organizationId);
        $query->where(static function (Builder $scope) use ($organizationId, $network, $table): void {
            $scope->where($table.'.organization_id', $organizationId)->orWhere(static fn (Builder $published) => $published->whereIn($table.'.organization_id', $network)->where($table.'.status', 'active')->where($table.'.is_visible_in_marketplace', true));
        });
    }

    private static function projectQuery(int $organizationId): Builder
    {
        return Project::query()->where(static fn (Builder $scope) => $scope->where('projects.organization_id', $organizationId)
            ->orWhereHas('organizations', static fn (Builder $organizations) => $organizations->where('organizations.id', $organizationId)->where('project_organization.is_active', true)));
    }
}
