<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantDesignAdditionalMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class DesignAdditionalRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string { return 'design_additional'; }
    public function enabled(): bool { return true; }
    public function entities(): array
    {
        $result = [];
        foreach (Metadata::entityDefinitions() as $type => [$source, $model]) { $result[$type] = ['model' => $model, 'fields' => Metadata::fields()[$type]]; }
        return $result;
    }
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->entities() as $type => $record) {
            foreach ($this->query($type, $organizationId, $projectId)->lazyById(50) as $model) {
                yield $this->chunk($model, $type, $organizationId);
            }
        }
    }
    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if (! isset($this->entities()[$entityType])) { return []; }
        $model = $this->query($entityType, $organizationId, null)->whereKey($entityId)->first();
        return $model === null ? [] : [$this->chunk($model, $entityType, $organizationId)];
    }

    private function query(string $type, int $organizationId, ?int $projectId): Builder
    {
        $definitions = Metadata::entityDefinitions() + [
            'design_package' => ['', \App\BusinessModules\Features\DesignManagement\Models\DesignPackage::class],
            'design_artifact' => ['', \App\BusinessModules\Features\DesignManagement\Models\DesignArtifact::class],
            'design_artifact_version' => ['', \App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion::class],
            'design_review_comment' => ['', \App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment::class],
            'design_model_set' => ['', \App\BusinessModules\Features\DesignManagement\Models\DesignModelSet::class],
            'quality_defect' => ['', \App\BusinessModules\Features\QualityControl\Models\QualityDefect::class],
            'schedule_task' => ['', \App\Models\ScheduleTask::class],
        ];
        $class = $definitions[$type][1];
        $query = $class::query();
        $table = $query->getModel()->getTable();
        if (isset(Metadata::safeSelectColumns()[$type])) {
            $query->select(array_map(static fn (string $column): string => $table.'.'.$column, Metadata::safeSelectColumns()[$type]));
        }
        $global = isset(Metadata::globalCatalogEntities()[$type]);
        $hasOrganization = in_array('organization_id', $query->getModel()->getFillable(), true);
        $hasProject = in_array('project_id', $query->getModel()->getFillable(), true);
        if ($hasOrganization) { $query->where($table.'.organization_id', $organizationId); }
        elseif (! $global && ! isset(Metadata::parentColumns()[$type])) { $query->whereRaw('1 = 0'); }
        if ($hasProject) {
            $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id', $organizationId)
                ->orWhereIn('id', DB::table('project_organization')->where('organization_id', $organizationId)->select('project_id')))->select('id');
            $query->whereIn($table.'.project_id', $projects);
            if ($projectId !== null) { $query->where($table.'.project_id', $projectId); }
        } elseif ($projectId !== null && $global) { $query->whereRaw('1 = 0'); }
        foreach (Metadata::rowPredicates()[$type] ?? [] as $column => $value) { $query->where($table.'.'.$column, $value); }
        $parents = Metadata::parentColumns()[$type] ?? match ($type) {
            'design_artifact' => ['package_id' => ['type' => 'design_package', 'nullable' => false, 'match_project' => true]],
            'design_artifact_version' => ['artifact_id' => ['type' => 'design_artifact', 'nullable' => false, 'match_project' => true]],
            'design_review_comment' => ['package_id' => ['type' => 'design_package', 'nullable' => false, 'match_project' => true]],
            default => [],
        };
        foreach ($parents as $column => $parent) {
            $parentQuery = $this->query($parent['type'], $organizationId, $hasProject ? null : $projectId);
            $parentTable = $parentQuery->getModel()->getTable();
            if ($parent['match_project'] ?? false) { $parentQuery->whereColumn($parentTable.'.project_id', $table.'.project_id'); }
            foreach ($parent['matches'] ?? [] as $parentColumn => $childColumn) { $parentQuery->whereColumn($parentTable.'.'.$parentColumn, $table.'.'.$childColumn); }
            $query->where(static function (Builder $scope) use ($column, $parent, $parentQuery, $parentTable, $table): void {
                if ($parent['nullable']) { $scope->whereNull($table.'.'.$column)->orWhereIn($table.'.'.$column, $parentQuery->select($parentTable.'.'.($parent['key'] ?? 'id'))); }
                else { $scope->whereIn($table.'.'.$column, $parentQuery->select($parentTable.'.'.($parent['key'] ?? 'id'))); }
            });
        }
        if ($type === 'schedule_task') {
            $query->whereHas('schedule', static function (Builder $schedule) use ($organizationId, $projectId): void {
                $schedule->where('organization_id', $organizationId);
                if ($projectId !== null) { $schedule->where('project_id', $projectId); }
            });
        }
        return $query;
    }

    private function chunk(Model $model, string $type, int $organizationId): RagChunkData
    {
        $data = array_intersect_key($model->attributesToArray(), array_flip(Metadata::fields()[$type]));
        $project = $model->getAttribute('project_id');
        if ($type === 'design_model_set_revision' || $type === 'bim_progress_group_element') {
            $parentType = $type === 'design_model_set_revision' ? 'design_model_set' : 'bim_progress_group';
            $column = $type === 'design_model_set_revision' ? 'model_set_id' : 'group_id';
            $project = $this->query($parentType, $organizationId, null)->whereKey($model->getAttribute($column))->value('project_id');
        }
        $projectId = is_numeric($project) ? (int) $project : null;
        if ($projectId !== null) { $data['project_id'] = $projectId; }
        $title = (string) ($data['title'] ?? $data['name'] ?? $data['sheet_title'] ?? $data['document_title'] ?? $data['code'] ?? $model->getKey());
        return new RagChunkData($organizationId, $projectId, $this->sourceType(), $type, $model->getKey(), $title,
            $title."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $data, $model->updated_at);
    }
}
