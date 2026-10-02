<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOperationsBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use App\Services\Entitlements\OrganizationEntitlementService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

abstract class OperationsBusinessRagSource implements RagSourceCollectorInterface
{
    public function enabled(): bool
    {
        return true;
    }

    public function entities(): array
    {
        return array_filter(Metadata::recordDefinitions(), fn (array $record): bool => $record['source'] === $this->sourceType());
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $activeModules = $this->activeModules($organizationId);
        foreach ($this->entities() as $type => $record) {
            $query = $this->query($type, $organizationId, $projectId, $activeModules);
            $model = $query->getModel();
            foreach ($query->lazyById(50, $model->getQualifiedKeyName(), $model->getKeyName()) as $row) {
                yield $this->chunk($row, $type, $record, $organizationId, $activeModules);
            }
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        $record = $this->entities()[$entityType] ?? null;
        if ($record === null) { return []; }
        $activeModules = $this->activeModules($organizationId);
        $model = $this->query($entityType, $organizationId, null, $activeModules)->whereKey($entityId)->first();
        return $model === null ? [] : [$this->chunk($model, $entityType, $record, $organizationId, $activeModules)];
    }

    private function query(string $type, int $organizationId, ?int $projectId, array $activeModules, array $seen = [], bool $skipSelfReference = false): Builder
    {
        $record = Metadata::sourceScopeRecords()[$type];
        $class = $record['model'];
        $query = $class::query();
        $table = $query->getModel()->getTable();
        $columns = array_values(array_unique([$query->getModel()->getKeyName(), ...$record['rag_fields'], ...$record['version_columns'], ...array_keys($record['parents']),
            ...($record['project_column'] === null ? [] : [$record['project_column']])]));
        $query->select(array_map(static fn (string $column): string => $table.'.'.$column, $columns));
        $modules = Metadata::domainModuleAlternatives()[$record['domain'] ?? ''] ?? (isset($record['module']) ? [$record['module']] : []);
        if ($modules !== [] && array_intersect($modules, $activeModules) === []) { return $query->whereRaw('1 = 0'); }
        if (count($seen) >= 16) { return $query->whereRaw('1 = 0'); }
        if ($type === 'project') {
            $query->where(static fn (Builder $scope): Builder => $scope->where($table.'.organization_id', $organizationId)
                ->orWhereIn($table.'.id', DB::table('project_organization')->where('organization_id', $organizationId)->select('project_id')));
        } elseif ($record['organization_column'] !== null) {
            $query->where($table.'.'.$record['organization_column'], $organizationId);
        } elseif (! array_filter($record['parents'], static fn (array $parent): bool => ! $parent['nullable'])) {
            $query->whereRaw('1 = 0');
        }
        if ($record['project_column'] !== null) {
            $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id', $organizationId)
                ->orWhereIn('id', DB::table('project_organization')->where('organization_id', $organizationId)->select('project_id')))->select('id');
            $query->where(static fn (Builder $scope): Builder => $scope->whereNull($table.'.'.$record['project_column'])->orWhereIn($table.'.'.$record['project_column'], $projects));
            if ($projectId !== null) { $query->where($table.'.'.$record['project_column'], $projectId); }
        } elseif ($projectId !== null && $type === 'warehouse_identifier') {
            $warehouses = $this->query('warehouse', $organizationId, $projectId, $activeModules)->select('organization_warehouses.id');
            $targets = [];
            foreach (Metadata::identifierTargets() as $kind => $targetType) {
                $target = $this->query($targetType, $organizationId, $projectId, $activeModules, [...$seen, $type]);
                $targets[$kind] = $target->select($target->getModel()->getQualifiedKeyName());
            }
            $query->where(static function (Builder $scope) use ($table, $warehouses, $targets): void {
                $scope->whereIn($table.'.warehouse_id', $warehouses);
                foreach ($targets as $kind => $target) {
                    $scope->orWhere(static fn (Builder $branch) => $branch->where($table.'.entity_type', $kind)->whereIn($table.'.entity_id', $target));
                }
            });
        } elseif ($projectId !== null) {
            $parent = $this->projectParent($type);
            if ($parent === null) { $query->whereRaw('1 = 0'); }
            else {
                [$column, $parentType] = $parent;
                $parentQuery = $this->query($parentType, $organizationId, $projectId, $activeModules, [...$seen, $type]);
                $query->whereIn($table.'.'.$column, $parentQuery->select($parentQuery->getModel()->getQualifiedKeyName()));
            }
        }
        foreach ($record['parents'] as $column => $parent) {
            $selfReference = $parent['type'] === $type && ($parent['reference_only'] ?? false) === true;
            if ($selfReference && $skipSelfReference) { continue; }
            if (in_array($parent['type'], [...$seen, $type], true) && ! $selfReference) { $query->whereRaw('1 = 0'); continue; }
            $parentQuery = $this->query($parent['type'], $organizationId, null, $activeModules, [...$seen, $type], $selfReference);
            foreach ($parent['matches'] ?? [] as $parentColumn => $childColumn) {
                $parentQuery->whereColumn($parentQuery->getModel()->getTable().'.'.$parentColumn, $table.'.'.$childColumn);
            }
            $query->where(static function (Builder $scope) use ($column, $parent, $parentQuery, $table): void {
                if ($parent['nullable']) { $scope->whereNull($table.'.'.$column)->orWhereIn($table.'.'.$column, $parentQuery->select($parentQuery->getModel()->getQualifiedKeyName())); }
                else { $scope->whereIn($table.'.'.$column, $parentQuery->select($parentQuery->getModel()->getQualifiedKeyName())); }
            });
        }
        if ($type === 'warehouse_identifier') {
            $targets = [];
            foreach (Metadata::identifierTargets() as $kind => $parentType) {
                $parent = $this->query($parentType, $organizationId, null, $activeModules, [...$seen, $type]);
                if (in_array($kind, ['zone', 'cell', 'logistic_unit'], true)) {
                    $parent->where(static fn (Builder $match) => $match->whereNull($table.'.warehouse_id')
                        ->orWhereColumn($parent->getModel()->getTable().'.warehouse_id', $table.'.warehouse_id'));
                }
                $targets[$kind] = $parent->select($parent->getModel()->getQualifiedKeyName());
            }
            $query->where(static function (Builder $scope) use ($table, $targets): void {
                $scope->whereRaw('1 = 0');
                foreach ($targets as $kind => $parent) {
                    $scope->orWhere(static fn (Builder $branch) => $branch->where($table.'.entity_type', $kind)->whereIn($table.'.entity_id', $parent));
                }
            });
        }
        if (in_array($type, ['safety_incident_row', 'safety_transition_event'], true)) {
            $query->where(static function (Builder $scope) use ($organizationId, $table): void {
                $scope->whereRaw('1 = 0');
                foreach (['incident' => 'safety_incidents', 'violation' => 'safety_violations', 'corrective_action' => 'safety_corrective_actions'] as $kind => $parentTable) {
                    $scope->orWhere(static fn (Builder $branch): Builder => $branch->where($table.'.subject_type', $kind)
                        ->whereIn($table.'.subject_id', DB::table($parentTable)->where('organization_id', $organizationId)->whereNull('deleted_at')->select('id')));
                }
            });
        }
        return $query;
    }

    private function activeModules(int $organizationId): array
    {
        return app(OrganizationEntitlementService::class)->getEffectiveModules($organizationId)->pluck('slug')->all();
    }

    private function projectParent(string $type, array $seen = []): ?array
    {
        $records = Metadata::sourceScopeRecords();
        foreach ($records[$type]['parents'] as $column => $parent) {
            if (in_array($parent['type'], [...$seen, $type], true)) { continue; }
            if ($records[$parent['type']]['project_column'] !== null || $this->projectParent($parent['type'], [...$seen, $type]) !== null) { return [$column, $parent['type']]; }
        }
        return null;
    }

    private function projectId(Model $model, string $type, int $organizationId, array $activeModules, array $seen = []): ?int
    {
        $record = Metadata::sourceScopeRecords()[$type];
        if ($type === 'warehouse_identifier' && $model->getAttribute('warehouse_id') === null) {
            $targetType = Metadata::identifierTargets()[(string) $model->getAttribute('entity_type')] ?? null;
            if ($targetType !== null) {
                $target = $this->query($targetType, $organizationId, null, $activeModules)->whereKey($model->getAttribute('entity_id'))->first();
                return $target === null ? null : $this->projectId($target, $targetType, $organizationId, $activeModules, [...$seen, $type]);
            }
        }
        if ($record['project_column'] !== null && is_numeric($model->getAttribute($record['project_column']))) { return (int) $model->getAttribute($record['project_column']); }
        $parent = $this->projectParent($type, $seen);
        if ($parent === null) { return null; }
        [$column, $parentType] = $parent;
        $id = $model->getAttribute($column);
        if ($id === null) { return null; }
        $parentModel = $this->query($parentType, $organizationId, null, $activeModules)->whereKey($id)->first();
        return $parentModel === null ? null : $this->projectId($parentModel, $parentType, $organizationId, $activeModules, [...$seen, $type]);
    }

    private function chunk(Model $model, string $type, array $record, int $organizationId, array $activeModules): RagChunkData
    {
        $data = array_intersect_key($model->getRawOriginal(), array_flip($record['rag_fields']));
        foreach ($data as $field => $value) { if (is_string($value)) { $data[$field] = mb_substr($value, 0, 5000); } }
        $title = $record['label'].' №'.(string) $model->getKey();
        if (isset($data['title']) || isset($data['name'])) { $title .= ': '.mb_substr((string) ($data['title'] ?? $data['name']), 0, 200); }
        $updated = null;
        foreach ($record['version_columns'] as $column) {
            $value = $model->getAttribute($column);
            if ($value !== null) { $updated = $value instanceof DateTimeInterface ? $value : CarbonImmutable::parse((string) $value); break; }
        }
        $projectId = $this->projectId($model, $type, $organizationId, $activeModules);
        $entityId = $model->getKey();
        if (! is_int($entityId) && ! is_string($entityId)) { throw new LogicException('Business source requires a persisted scalar identity.'); }
        return new RagChunkData($organizationId, $projectId, $this->sourceType(), $type, $entityId, $title,
            $title."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $data, $updated);
    }
}
