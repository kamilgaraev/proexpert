<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderMetadata;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

abstract class ScopedFinanceTenderRagSource implements RagSourceCollectorInterface
{
    public function enabled(): bool
    {
        return true;
    }

    public function entities(): array
    {
        return array_filter(AssistantFinanceTenderMetadata::publicInventory(), fn (array $definition, string $type): bool => AssistantFinanceTenderMetadata::domainFor($type) === $this->sourceType(), ARRAY_FILTER_USE_BOTH);
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
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
        $definition = $this->entities()[$entityType] ?? null;
        if ($definition === null || $organizationId < 1) {
            return [];
        }
        $model = $this->collectionQuery($entityType, $organizationId)->whereKey($entityId)->first();

        return $model === null ? [] : [$this->chunk($model, $entityType, $definition['fields'], $organizationId)];
    }

    public static function scopedQuery(string $type, int $organizationId, ?int $projectId = null): Builder
    {
        $definition = AssistantFinanceTenderMetadata::inventory()[$type] ?? null;
        if ($definition === null) {
            throw new RuntimeException('assistant_domain_entity_unsupported');
        }
        $class = $definition['model'];
        $query = $class::query();
        $table = $query->getModel()->getTable();
        if ($organizationId < 1) {
            return $query->whereRaw('1 = 0');
        }
        if (in_array('organization_id', $definition['fields'], true)) {
            if (isset(AssistantFinanceTenderMetadata::organizationNullableCatalogs()[$type])) {
                $query->where(static fn (Builder $scope) => $scope->where($table.'.organization_id', $organizationId)->orWhereNull($table.'.organization_id'));
            } else {
                $query->where($table.'.organization_id', $organizationId);
            }
        } elseif (! isset(AssistantFinanceTenderMetadata::parentColumns()[$type])) {
            return $query->whereRaw('1 = 0');
        }
        $hasProject = in_array('project_id', $definition['fields'], true);
        if ($hasProject) {
            $projectQuery = self::projectQuery($organizationId);
            $query->where(static fn (Builder $scope) => $scope->whereNull($table.'.project_id')->orWhereIn($table.'.project_id', $projectQuery->select('projects.id')));
            if ($projectId !== null) {
                $query->where($table.'.project_id', $projectId);
            }
        }
        $inheritedProject = false;
        foreach (AssistantFinanceTenderMetadata::parentColumns()[$type] ?? [] as $column => $parent) {
            if ($parent['type'] === 'project') {
                continue;
            }
            $parentDefinition = AssistantFinanceTenderMetadata::inventory()[$parent['type']] ?? null;
            if ($parentDefinition !== null) {
                $propagateProject = ! $hasProject && self::hasProjectLineage($parent['type']);
                $parentQuery = self::scopedQuery($parent['type'], $organizationId, $propagateProject ? $projectId : null);
                $inheritedProject = $inheritedProject || $propagateProject;
            } else {
                $parentClass = match ($parent['type']) {
                    'contract' => \App\Models\Contract::class,
                    'crm_company' => \App\BusinessModules\Features\Crm\Models\CrmCompany::class,
                    'crm_contact' => \App\BusinessModules\Features\Crm\Models\CrmContact::class,
                    'crm_deal' => \App\BusinessModules\Features\Crm\Models\CrmDeal::class,
                    'commercial_proposal' => \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal::class,
                    default => throw new RuntimeException('assistant_domain_parent_unsupported'),
                };
                $parentQuery = $parentClass::query();
                $parentQuery->where($parentQuery->getModel()->getTable().'.organization_id', $organizationId);
            }
            $parentKey = $parent['key'] ?? 'id';
            $parentTable = $parentQuery->getModel()->getTable();
            foreach ($parent['matches'] ?? [] as $parentColumn => $childColumn) {
                $parentQuery->whereColumn($parentTable.'.'.$parentColumn, $table.'.'.$childColumn);
            }
            $parentQuery->select($parentTable.'.'.$parentKey);
            $query->where(static function (Builder $scope) use ($parent, $column, $table, $parentQuery): void {
                if ($parent['nullable']) {
                    $scope->whereNull($table.'.'.$column)->orWhereIn($table.'.'.$column, $parentQuery);
                } else {
                    $scope->whereIn($table.'.'.$column, $parentQuery);
                }
            });
        }
        if ($projectId !== null && ! $hasProject && ! $inheritedProject) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    protected function chunk(Model $model, string $type, array $fields, int $organizationId): RagChunkData
    {
        $fields = array_values(array_intersect($fields, AssistantFinanceTenderMetadata::publicFields()[$type] ?? []));
        $attributes = array_intersect_key($model->getAttributes(), array_flip($fields));
        foreach (AssistantFinanceTenderMetadata::numericFields() as $field) {
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
        foreach (AssistantFinanceTenderMetadata::numericFields() as $field) {
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
        $metadata = $data;
        $revision = AssistantFinanceTenderSourceSchema::revision($type);
        if ($revision !== null) {
            $metadata[AssistantFinanceTenderSourceSchema::FIELD] = $revision;
        }

        return new RagChunkData($organizationId, is_numeric($projectId) ? (int) $projectId : null,
            $this->sourceType(), $type, $model->getKey(), $title,
            $title."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $metadata,
            $updatedAt instanceof DateTimeInterface ? $updatedAt : null);
    }

    private static function hasProjectLineage(string $type): bool
    {
        if (in_array('project_id', AssistantFinanceTenderMetadata::inventory()[$type]['fields'] ?? [], true)) {
            return true;
        }
        foreach (AssistantFinanceTenderMetadata::parentColumns()[$type] ?? [] as $parent) {
            if (isset(AssistantFinanceTenderMetadata::inventory()[$parent['type']]) && self::hasProjectLineage($parent['type'])) {
                return true;
            }
        }

        return false;
    }

    protected function collectionQuery(string $type, int $organizationId, ?int $projectId = null): Builder
    {
        $query = self::scopedQuery($type, $organizationId, $projectId);
        if (! in_array('project_id', AssistantFinanceTenderMetadata::inventory()[$type]['fields'], true)) {
            $table = $query->getModel()->getTable();
            foreach (AssistantFinanceTenderMetadata::parentColumns()[$type] ?? [] as $column => $parent) {
                if (! isset(AssistantFinanceTenderMetadata::inventory()[$parent['type']]) || ! self::hasProjectLineage($parent['type'])) {
                    continue;
                }
                $projection = self::projectProjection($parent['type'], $organizationId);
                $parentTable = $projection->getModel()->getTable();
                $projection->whereColumn($parentTable.'.'.($parent['key'] ?? 'id'), $table.'.'.$column)->limit(1);
                $query->select($table.'.*')->addSelect(['assistant_project_id' => $projection]);
                break;
            }
        }

        return $query;
    }

    private static function projectProjection(string $type, int $organizationId): Builder
    {
        $query = self::scopedQuery($type, $organizationId);
        $table = $query->getModel()->getTable();
        if (in_array('project_id', AssistantFinanceTenderMetadata::inventory()[$type]['fields'], true)) {
            return $query->select($table.'.project_id');
        }
        foreach (AssistantFinanceTenderMetadata::parentColumns()[$type] ?? [] as $column => $parent) {
            if (! isset(AssistantFinanceTenderMetadata::inventory()[$parent['type']]) || ! self::hasProjectLineage($parent['type'])) {
                continue;
            }
            $projection = self::projectProjection($parent['type'], $organizationId);
            $parentTable = $projection->getModel()->getTable();
            $projection->whereColumn($parentTable.'.'.($parent['key'] ?? 'id'), $table.'.'.$column)->limit(1);

            return $query->selectSub($projection, 'derived_project_id');
        }

        return $query->selectRaw('NULL')->whereRaw('1 = 0');
    }

    private static function projectQuery(int $organizationId): Builder
    {
        return Project::query()->where(static fn (Builder $scope) => $scope->where('projects.organization_id', $organizationId)
            ->orWhereHas('organizations', static fn (Builder $organizations) => $organizations->where('organizations.id', $organizationId)->where('project_organization.is_active', true)));
    }
}
