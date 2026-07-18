<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;

final class InMemoryBuildingModelStore implements BuildingModelStore
{
    private array $models = [];

    private array $evidence = [];

    private array $payloads = [];

    private int $nextId = 1;

    public function transaction(BuildingModelOperationContext $context, callable $callback): mixed
    {
        return $callback();
    }

    public function insertOrGet(BuildingModelOperationContext $context, NormalizedBuildingModelData $model): StoredBuildingModel
    {
        $slot = implode(':', [$context->organizationId, $context->projectId, $context->sessionId, $context->inputVersion]);
        $contentVersion = $model->contentVersion();
        if (isset($this->models[$slot])) {
            $stored = $this->models[$slot];
            if (! hash_equals($stored->contentVersion, $contentVersion)) {
                throw new BuildingModelContentCollision;
            }

            return new StoredBuildingModel($stored->id, $context, $stored->modelVersion, $stored->contentVersion, false);
        }
        $stored = new StoredBuildingModel($this->nextId++, $context, $model->modelVersion, $contentVersion, true);
        $this->models[$slot] = $stored;
        $this->payloads[$stored->id] = $model;

        return $stored;
    }

    public function attachEvidence(StoredBuildingModel $stored, array $evidenceIds): void
    {
        $this->evidence[$stored->id] = $evidenceIds;
    }

    public function find(BuildingModelOperationContext $context): ?StoredBuildingModel
    {
        $slot = implode(':', [$context->organizationId, $context->projectId, $context->sessionId, $context->inputVersion]);
        $stored = $this->models[$slot] ?? null;

        return $stored === null ? null : new StoredBuildingModel($stored->id, $context, $stored->modelVersion, $stored->contentVersion, false);
    }

    public function latest(BuildingModelOperationContext $context): ?StoredBuildingModel
    {
        $latest = null;
        foreach ($this->models as $stored) {
            if ($stored->context->organizationId !== $context->organizationId
                || $stored->context->projectId !== $context->projectId
                || $stored->context->sessionId !== $context->sessionId
                || ($latest !== null && $stored->id <= $latest->id)) {
                continue;
            }
            $latest = $stored;
        }

        return $latest === null ? null : new StoredBuildingModel(
            $latest->id,
            $latest->context,
            $latest->modelVersion,
            $latest->contentVersion,
            false,
        );
    }

    public function model(StoredBuildingModel $stored): ?NormalizedBuildingModelData
    {
        return $this->payloads[$stored->id] ?? null;
    }

    public function evidenceIds(StoredBuildingModel $stored): array
    {
        return $this->evidence[$stored->id] ?? [];
    }
}
