<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class ModelDomainRagSource implements RagSourceCollectorInterface
{
    abstract public function entities(): array;

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->entities() as $type => $definition) {
            foreach ($this->query($definition['model'], $organizationId, $projectId)->lazyById(50) as $model) {
                yield $this->chunk($model, $type, $definition['fields'], $organizationId);
            }
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        $definition = $this->entities()[$entityType] ?? null;
        if ($definition === null) {
            return [];
        }
        $model = $this->query($definition['model'], $organizationId, null)->whereKey($entityId)->first();
        return $model === null ? [] : [$this->chunk($model, $entityType, $definition['fields'], $organizationId)];
    }

    protected function query(string $class, int $organizationId, ?int $projectId): Builder
    {
        $query = $class::query()->where('organization_id', $organizationId);
        if ($projectId !== null) {
            if (in_array('project_id', $query->getModel()->getFillable(), true)) {
                $query->where('project_id', $projectId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        return $query;
    }

    private function chunk(Model $model, string $entityType, array $fields, int $organizationId): RagChunkData
    {
        $data = array_intersect_key($model->attributesToArray(), array_flip($fields));
        $title = (string) ($data['title'] ?? $data['name'] ?? $data['full_name'] ?? $data['subject'] ?? $data['number'] ?? $model->getKey());
        return new RagChunkData(
            organizationId: $organizationId,
            projectId: is_numeric($model->getAttribute('project_id')) ? (int) $model->getAttribute('project_id') : null,
            sourceType: $this->sourceType(),
            entityType: $entityType,
            entityId: $model->getKey(),
            title: $title,
            content: $title."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            metadata: $data,
            updatedAt: $model->updated_at
        );
    }
}
