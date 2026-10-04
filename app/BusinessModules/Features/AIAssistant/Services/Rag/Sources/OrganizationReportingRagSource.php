<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOrganizationReportingMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OrganizationReportingRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string { return 'organization_reporting'; }
    public function enabled(): bool { return true; }
    public function entities(): array
    {
        return array_map(static fn (array $row): array => ['model' => $row[0],'fields' => $row[1]], Metadata::records());
    }
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->entities() as $type => $record) {
            $models = $type === 'approved_estimate_resource_price' ? $this->priceModels($organizationId, $projectId)
                : $this->scopedQuery($type, $organizationId, $projectId)->lazyById(50);
            foreach ($models as $model) {
                yield $this->chunk($model, $type, $organizationId);
            }
        }
    }

    private function priceModels(int $organizationId, ?int $projectId): \Generator
    {
        if ($organizationId < 1 || $projectId !== null) { return; }
        foreach (Metadata::publishedPriceScopes() as $scope) {
            $query = $this->scopedQuery('approved_estimate_resource_price', $organizationId);
            foreach ($scope as $column => $value) { $query->where('estimate_resource_prices.'.$column, $value); }
            yield from $query->lazyById(50);
        }
    }
    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if (! isset($this->entities()[$entityType])) { return []; }
        $model = $this->scopedQuery($entityType, $organizationId)->whereKey($entityId)->first();
        return $model === null ? [] : [$this->chunk($model, $entityType, $organizationId)];
    }
    public function scopedQuery(string $type, int $organizationId, ?int $projectId = null, array $seen = []): Builder
    {
        $record = Metadata::records()[$type] ?? null;
        if ($record === null || in_array($type, $seen, true)) { throw new RuntimeException('assistant_reporting_entity_unsupported'); }
        $query = $record[0]::query();
        $table = $query->getModel()->getTable();
        $fields = Metadata::fields()[$type];
        $query->select(array_map(static fn (string $column): string => $table.'.'.$column, Metadata::safeSelectColumns()[$type]));
        if ($organizationId < 1) { return $query->whereRaw('1 = 0'); }
        $organizationColumn = Metadata::organizationColumns()[$type] ?? 'organization_id';
        $parents = Metadata::parentColumns()[$type] ?? [];
        $global = Metadata::globalCatalogEntities()[$type] ?? false;
        if (in_array($organizationColumn, $fields, true)) { $query->where($table.'.'.$organizationColumn, $organizationId); }
        elseif (! $global && $parents === []) { return $query->whereRaw('1 = 0'); }
        $hasProject = in_array('project_id', $fields, true);
        if ($hasProject) {
            $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id', $organizationId)
                ->orWhereIn('id', DB::table('project_organization')->where('organization_id', $organizationId)->select('project_id')))->select('id');
            $query->where(static fn (Builder $scope): Builder => $scope->whereNull($table.'.project_id')->orWhereIn($table.'.project_id', $projects));
            if ($projectId !== null) { $query->where($table.'.project_id', $projectId); }
        }
        foreach (Metadata::rowPredicates()[$type] ?? [] as $column => $value) { $query->where($table.'.'.$column, $value); }
        foreach (Metadata::rowColumnMatches()[$type] ?? [] as $left => $right) { $query->whereColumn($table.'.'.$left, $table.'.'.$right); }
        Metadata::applyPublicationScope($type,$query);
        $inheritedProject = false;
        foreach ($parents as $column => $parent) {
            if (! isset(Metadata::records()[$parent['type']])) {
                $class = match ($parent['type']) {
                    'contract' => \App\Models\Contract::class,
                    'performance_act' => \App\Models\ContractPerformanceAct::class,
                    'payment_document' => \App\BusinessModules\Core\Payments\Models\PaymentDocument::class,
                    default => throw new RuntimeException('assistant_reporting_parent_unsupported'),
                };
                $parentQuery = $class::query()->where('organization_id', $organizationId);
            } else {
                $inherits = ! $hasProject && $this->hasProjectLineage($parent['type']);
                $inheritedProject = $inheritedProject || $inherits;
                $parentQuery = $type === 'approved_estimate_norm' && $column === 'section_id'
                    ? Metadata::records()[$parent['type']][0]::query()
                    : $this->scopedQuery($parent['type'], $organizationId, $inherits ? $projectId : null, [...$seen,$type]);
            }
            $parentTable = $parentQuery->getModel()->getTable();
            if ($parent['match_project'] ?? false) { $parentQuery->whereRaw($parentTable.'.project_id IS NOT DISTINCT FROM '.$table.'.project_id'); }
            foreach ($parent['matches'] ?? [] as $parentColumn => $childColumn) { $parentQuery->whereColumn($parentTable.'.'.$parentColumn, $table.'.'.$childColumn); }
            if (empty($parent['matches']) && ! ($parent['match_project'] ?? false)
                && ! in_array($type, ['approved_estimate_norm_resource', 'approved_estimate_resource_price'], true)) {
                $parentQuery->select($parentTable.'.'.($parent['key'] ?? 'id'));
                $query->where(static function (Builder $scope) use ($column,$parent,$parentQuery,$table): void {
                    if ($parent['nullable']) { $scope->whereNull($table.'.'.$column)->orWhereIn($table.'.'.$column, $parentQuery); }
                    else { $scope->whereIn($table.'.'.$column, $parentQuery); }
                });
                continue;
            }
            $parentQuery->whereColumn($parentTable.'.'.($parent['key'] ?? 'id'), $table.'.'.$column)->selectRaw('1');
            if ($type === 'approved_estimate_norm_resource' && $column === 'estimate_norm_id') {
                $query->whereRaw('(SELECT EXISTS ('.$parentQuery->toSql().'))', $parentQuery->getBindings());
                continue;
            }
            if ($type === 'approved_estimate_resource_price' && $column === 'construction_resource_id') {
                $query->where(static fn (Builder $scope): Builder => $scope->whereNull($table.'.'.$column)
                    ->orWhereRaw('(SELECT EXISTS ('.$parentQuery->toSql().'))', $parentQuery->getBindings()));
                continue;
            }
            $query->where(static function (Builder $scope) use ($column,$parent,$parentQuery,$table): void {
                if ($parent['nullable']) { $scope->whereNull($table.'.'.$column)->orWhereExists($parentQuery->toBase()); }
                else { $scope->whereExists($parentQuery->toBase()); }
            });
        }
        if ($projectId !== null && ! $hasProject && ! $inheritedProject) { $query->whereRaw('1 = 0'); }
        return $query;
    }
    private function hasProjectLineage(string $type): bool
    {
        if (in_array('project_id', Metadata::fields()[$type], true)) { return true; }
        foreach (Metadata::parentColumns()[$type] ?? [] as $parent) {
            if (isset(Metadata::records()[$parent['type']]) && $this->hasProjectLineage($parent['type'])) { return true; }
        }
        return false;
    }
    private function projectId(Model $model, string $type, int $organizationId): ?int
    {
        $id = $model->getAttribute('project_id');
        if (is_numeric($id)) { return (int) $id; }
        foreach (Metadata::parentColumns()[$type] ?? [] as $column => $parent) {
            if (! isset(Metadata::records()[$parent['type']]) || ! $this->hasProjectLineage($parent['type'])) { continue; }
            $parentModel = $this->scopedQuery($parent['type'], $organizationId)->whereKey($model->getAttribute($column))->first();
            if ($parentModel !== null) { return $this->projectId($parentModel, $parent['type'], $organizationId); }
        }
        return null;
    }
    private function chunk(Model $model, string $type, int $organizationId): RagChunkData
    {
        $data = [];
        foreach (Metadata::fields()[$type] as $field) {
            $value = $model->getAttribute($field);
            if ($value instanceof BackedEnum) { $value = $value->value; }
            if ($value instanceof \DateTimeInterface) { $value = $value->format(DATE_ATOM); }
            if ($value === null || is_scalar($value)) { $data[$field] = $value; }
        }
        $projectId = $this->projectId($model, $type, $organizationId);
        if ($projectId !== null) { $data['project_id'] = $projectId; }
        $title = (string) ($data['title'] ?? $data['name'] ?? $data['filename'] ?? $data['label'] ?? Metadata::entityLabels()[$type].' '.$model->getKey());
        $updated = null;
        foreach (Metadata::versionColumns()[$type] as $column) {
            $value = $model->getAttribute($column);
            if ($value !== null) { $updated = $value instanceof \DateTimeInterface ? $value : \Carbon\CarbonImmutable::parse((string) $value); break; }
        }
        return new RagChunkData($organizationId,$projectId,$this->sourceType(),$type,$model->getKey(),$title,
            $title."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),$data,$updated);
    }
}
