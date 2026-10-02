<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

class CoreBusinessRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string { return 'core_business'; }
    public function enabled(): bool { return true; }
    public function entities(): array
    {
        return array_filter(Metadata::records(), fn (array $record): bool => $record['indexed'] && $record['source'] === $this->sourceType());
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
    private function record(string $type): array
    {
        $records = Metadata::records();
        if (isset($records[$type])) { return $records[$type]; }
        $external = match ($type) {
            'project' => [Project::class, 'organization_id', 'id', []],
            'contract' => [\App\Models\Contract::class, 'organization_id', 'project_id', []],
            'estimate' => [\App\Models\Estimate::class, 'organization_id', 'project_id', []],
            'estimate_item' => [\App\Models\EstimateItem::class, null, null, ['estimate_id' => ['type' => 'estimate', 'nullable' => false, 'key' => 'id']]],
            'completed_work' => [\App\Models\CompletedWork::class, 'organization_id', 'project_id', []],
            'material' => [\App\Models\Material::class, 'organization_id', null, []],
            'work_type' => [\App\Models\WorkType::class, 'organization_id', null, []],
            'performance_act' => [\App\Models\ContractPerformanceAct::class, null, null, ['contract_id' => ['type' => 'contract', 'nullable' => false, 'key' => 'id']]],
            'schedule' => [\App\Models\ProjectSchedule::class, 'organization_id', 'project_id', []],
            'schedule_task' => [\App\Models\ScheduleTask::class, 'organization_id', null, ['schedule_id' => ['type' => 'schedule', 'nullable' => false, 'key' => 'id']]],
            'payment_document' => [\App\BusinessModules\Core\Payments\Models\PaymentDocument::class, 'organization_id', 'project_id', []],
            'time_entry' => [\App\Models\TimeEntry::class, 'organization_id', 'project_id', []],
            default => throw new LogicException('assistant_core_unknown_parent'),
        };
        [$model, $organization, $project, $parents] = $external;
        return ['model' => $model, 'organization_column' => $organization, 'project_column' => $project, 'parents' => $parents,
            'actor_column' => null, 'global' => false, 'predicates' => [], 'fields' => ['id'], 'version_columns' => []];
    }
    private function query(string $type, int $organizationId, ?int $projectId, array $seen = [], bool $skipReferences = false): Builder
    {
        if (in_array($type, $seen, true)) { throw new LogicException('assistant_core_parent_cycle'); }
        $record = $this->record($type);
        $class = $record['model'];
        $query = $class::query();
        $table = $query->getModel()->getTable();
        $columns = array_values(array_unique([...$record['fields'], ...array_keys($record['parents']), ...array_keys($record['predicates']),
            ...array_filter([$record['organization_column'], $record['project_column']])]));
        $query->select(array_map(static fn (string $column): string => $table.'.'.$column, $columns));
        if ($record['actor_column'] !== null) { return $query->whereRaw('1 = 0'); }
        if ($record['organization_column'] !== null && $type !== 'project') { $query->where($table.'.'.$record['organization_column'], $organizationId); }
        elseif (! $record['global'] && $record['parents'] === []) { $query->whereRaw('1 = 0'); }
        foreach ($record['predicates'] as $column => $value) { $query->where($table.'.'.$column, $value); }
        if ($type === 'project') {
            $query->where(static fn (Builder $scope): Builder => $scope->where($table.'.organization_id', $organizationId)
                ->orWhereIn($table.'.id', DB::table('project_organization')->where('organization_id', $organizationId)->where('is_active', true)->select('project_id')));
        }
        if ($record['project_column'] !== null && $type !== 'project') {
            $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id', $organizationId)
                ->orWhereIn('id', DB::table('project_organization')->where('organization_id', $organizationId)->where('is_active', true)->select('project_id')))->select('id');
            $query->where(static fn (Builder $scope): Builder => $scope->whereNull($table.'.'.$record['project_column'])->orWhereIn($table.'.'.$record['project_column'], $projects));
        }
        if ($projectId !== null && $record['project_column'] !== null) { $query->where($table.'.'.$record['project_column'], $projectId); }
        $projectParent = $this->projectParent($type);
        if ($projectId !== null && $record['project_column'] === null && $projectParent === null) { $query->whereRaw('1 = 0'); }
        foreach ($record['parents'] as $column => $parent) {
            $referenceOnly = ($parent['reference_only'] ?? false) === true;
            if ($referenceOnly && $parent['type'] !== $type) { throw new LogicException('assistant_core_invalid_reference'); }
            if ($referenceOnly && $skipReferences) { continue; }
            $parentProject = $projectId !== null && $record['project_column'] === null && $projectParent !== null && $projectParent[0] === $column ? $projectId : null;
            $parentQuery = $referenceOnly ? $this->query($type, $organizationId, null, [], true)
                : $this->query($parent['type'], $organizationId, $parentProject, [...$seen, $type]);
            $parentTable = $parentQuery->getModel()->getTable();
            foreach ($parent['matches'] ?? [] as $parentColumn => $childColumn) { $parentQuery->whereColumn($parentTable.'.'.$parentColumn, $table.'.'.$childColumn); }
            $query->where(static function (Builder $scope) use ($column, $parent, $parentQuery, $parentTable, $table): void {
                if ($parent['nullable']) { $scope->whereNull($table.'.'.$column)->orWhereIn($table.'.'.$column, $parentQuery->select($parentTable.'.'.$parent['key'])); }
                else { $scope->whereIn($table.'.'.$column, $parentQuery->select($parentTable.'.'.$parent['key'])); }
            });
        }
        return $query;
    }
    private function projectParent(string $type, array $seen = []): ?array
    {
        foreach ($this->record($type)['parents'] as $column => $parent) {
            if (($parent['reference_only'] ?? false) === true) { continue; }
            if (in_array($parent['type'], [...$seen, $type], true)) { continue; }
            if ($this->record($parent['type'])['project_column'] !== null || $this->projectParent($parent['type'], [...$seen, $type]) !== null) { return [$column, $parent['type']]; }
        }
        return null;
    }
    private function projectId(Model $model, string $type, int $organizationId): ?int
    {
        $record = $this->record($type);
        if ($record['project_column'] !== null) {
            $value = $model->getAttribute($record['project_column']);
            return is_numeric($value) ? (int) $value : null;
        }
        $parent = $this->projectParent($type);
        if ($parent === null) { return null; }
        [$column, $parentType] = $parent;
        $id = $model->getAttribute($column);
        if ($id === null) { return null; }
        $parentModel = $this->query($parentType, $organizationId, null)->whereKey($id)->first();
        return $parentModel === null ? null : $this->projectId($parentModel, $parentType, $organizationId);
    }
    private function chunk(Model $model, string $type, array $record, int $organizationId): RagChunkData
    {
        $data = array_intersect_key($model->attributesToArray(), array_flip($record['rag_fields']));
        $title = (string) ($data['title'] ?? $data['name'] ?? $data['number'] ?? $data['code'] ?? $data['caption'] ?? $data['label'] ?? $model->getKey());
        $projectId = $this->projectId($model, $type, $organizationId);
        $data['project_id'] = $projectId;
        return new RagChunkData($organizationId, $projectId, $this->sourceType(), $type, $model->getKey(), $title,
            $record['label'].': '.$title."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $data, null);
    }
}
