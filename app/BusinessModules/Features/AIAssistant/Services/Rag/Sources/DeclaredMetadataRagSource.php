<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantWorkforceCatalogMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class DeclaredMetadataRagSource implements RagSourceCollectorInterface
{
    public function enabled(): bool
    {
        return true;
    }

    public function entities(): array
    {
        return array_filter(AssistantWorkforceCatalogMetadata::recordDefinitions(), fn (array $record): bool => $record['source'] === $this->sourceType());
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->entities() as $type => $record) {
            foreach ($this->query($type, $organizationId, $projectId)->lazyById(50) as $model) {
                yield $this->chunk($model, $type, $record, $organizationId);
            }
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        $record = $this->entities()[$entityType] ?? null;
        if ($record === null) { return []; }
        $model = $this->query($entityType, $organizationId, null)->whereKey($entityId)->first();
        return $model === null ? [] : [$this->chunk($model, $entityType, $record, $organizationId)];
    }

    private function query(string $type, int $organizationId, ?int $projectId, array $seen = []): Builder
    {
        $records = AssistantWorkforceCatalogMetadata::recordDefinitions();
        $record = $records[$type];
        $class = $record['model'];
        $query = $class::query();
        $table = $query->getModel()->getTable();
        $columns = array_unique([...$record['rag_fields'], ...$record['version_columns'], ...array_keys($record['parents']),
            ...($record['project_column'] === null ? [] : [$record['project_column']])]);
        $query->select(array_map(static fn (string $column): string => $table.'.'.$column, $columns));
        if ($record['organization_column'] !== null) {
            $public = AssistantWorkforceCatalogMetadata::publicCatalogEntities()[$type] ?? null;
            $query->where(static function (Builder $scope) use ($record, $organizationId, $public, $table): void {
                $scope->where($table.'.'.$record['organization_column'], $organizationId);
                if ($public !== null) { $scope->orWhere($table.'.'.$public['approval_column'], $public['approved_value']); }
            });
        } elseif (! isset(AssistantWorkforceCatalogMetadata::globalCatalogEntities()[$type]) && $record['parents'] === []) {
            $query->whereRaw('1 = 0');
        }
        if ($record['project_column'] !== null) {
            $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id', $organizationId)
                ->orWhereIn('id', DB::table('project_organization')->where('organization_id', $organizationId)->select('project_id')))->select('id');
            $query->where(static fn (Builder $scope): Builder => $scope->whereNull($table.'.'.$record['project_column'])
                ->orWhereIn($table.'.'.$record['project_column'], $projects));
            if ($projectId !== null) { $query->where($table.'.'.$record['project_column'], $projectId); }
        } elseif ($projectId !== null) {
            $parent = $this->projectParent($type);
            if ($parent === null) {
                $query->whereRaw('1 = 0');
            } else {
                [$column, $parentType] = $parent;
                $parentQuery = $this->query($parentType, $organizationId, $projectId, [...$seen, $type]);
                $parentTable = $parentQuery->getModel()->getTable();
                $query->whereIn($table.'.'.$column, $parentQuery->select($parentTable.'.id'));
            }
        }
        foreach ($record['parents'] as $column => $parent) {
            $parentType = $parent['type'];
            if (in_array($parentType, [...$seen, $type], true)) {
                $parentRecord = $records[$parentType];
                $parentQuery = $parentRecord['model']::query()->where($parentRecord['organization_column'], $organizationId);
            } else {
                $parentQuery = $this->query($parentType, $organizationId, null, [...$seen, $type]);
            }
            $parentTable = $parentQuery->getModel()->getTable();
            $query->where(static function (Builder $scope) use ($column, $parent, $parentQuery, $table, $parentTable): void {
                if ($parent['nullable']) { $scope->whereNull($table.'.'.$column)->orWhereIn($table.'.'.$column, $parentQuery->select($parentTable.'.id')); }
                else { $scope->whereIn($table.'.'.$column, $parentQuery->select($parentTable.'.id')); }
            });
        }
        return $query;
    }

    private function projectParent(string $type, array $seen = []): ?array
    {
        $records = AssistantWorkforceCatalogMetadata::recordDefinitions();
        foreach ($records[$type]['parents'] as $column => $parent) {
            if (in_array($parent['type'], [...$seen, $type], true)) { continue; }
            if ($records[$parent['type']]['project_column'] !== null || $this->projectParent($parent['type'], [...$seen, $type]) !== null) {
                return [$column, $parent['type']];
            }
        }
        return null;
    }

    private function projectId(Model $model, string $type, int $organizationId, array $seen = []): ?int
    {
        $record = AssistantWorkforceCatalogMetadata::recordDefinitions()[$type];
        if ($record['project_column'] !== null && is_numeric($model->getAttribute($record['project_column']))) {
            return (int) $model->getAttribute($record['project_column']);
        }
        $parent = $this->projectParent($type, $seen);
        if ($parent === null) { return null; }
        [$column, $parentType] = $parent;
        $id = $model->getAttribute($column);
        if ($id === null) { return null; }
        $parentModel = $this->query($parentType, $organizationId, null)->whereKey($id)->first();
        return $parentModel === null ? null : $this->projectId($parentModel, $parentType, $organizationId, [...$seen, $type]);
    }

    private function chunk(Model $model, string $type, array $record, int $organizationId): RagChunkData
    {
        $data = array_intersect_key($model->attributesToArray(), array_flip($record['rag_fields']));
        $title = (string) ($data['title'] ?? $data['name'] ?? $data['full_name'] ?? $data['statement_number'] ?? $data['package_number'] ?? $data['code'] ?? $model->getKey());
        if ($type === 'workforce_employee') { $title = trim(implode(' ', array_filter([$data['last_name'] ?? '', $data['first_name'] ?? '', $data['middle_name'] ?? '']))); }
        $updated = null;
        foreach ($record['version_columns'] as $column) {
            $value = $model->getAttribute($column);
            if ($value !== null) { $updated = $value instanceof DateTimeInterface ? $value : CarbonImmutable::parse((string) $value); break; }
        }
        $projectId = $this->projectId($model, $type, $organizationId);
        $data['project_id'] = $projectId;
        return new RagChunkData($organizationId, $projectId, $this->sourceType(), $type, $model->getKey(), $title,
            $title."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $data, $updated);
    }
}
