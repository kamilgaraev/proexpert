<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use InvalidArgumentException;

final readonly class BuildingModelRepository
{
    public function __construct(
        private BuildingModelStore $store,
        private EvidenceRepository $evidence,
        private ProjectModelRepository $projectModel,
    ) {}

    public function store(BuildingModelOperationContext $context, NormalizedBuildingModelData $model, ?callable $afterStore = null): StoredBuildingModel
    {
        return $this->store->transaction($context, function () use ($context, $model, $afterStore): StoredBuildingModel {
            $nodes = $this->evidence->activeNodesForUpdate(
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $model->evidenceIds,
            );
            if (array_map(static fn ($node): int => $node->id, $nodes) !== $model->evidenceIds) {
                throw new InvalidArgumentException('Building model evidence must be active and match the exact tenant scope.');
            }
            $stored = $this->store->insertOrGet($context, $model);
            $this->store->attachEvidence($stored, $model->evidenceIds);
            if ($afterStore !== null) {
                $afterStore($stored);
            }

            return $stored;
        });
    }

    public function current(BuildingModelOperationContext $context): ?StoredBuildingModel
    {
        return $this->validated($context, false);
    }

    public function currentModel(BuildingModelOperationContext $context): ?NormalizedBuildingModelData
    {
        $stored = $this->current($context);

        return $stored === null ? null : $this->store->model($stored);
    }

    public function latestCurrentModel(BuildingModelOperationContext $context): ?NormalizedBuildingModelData
    {
        $stored = $this->validated($context, true);

        return $stored === null ? null : $this->store->model($stored);
    }

    public function currentFacts(BuildingModelOperationContext $context, ?string $entityId = null): array
    {
        if ($this->validated($context, false) === null) {
            return [];
        }

        return $this->projectModel->currentFacts(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            $entityId,
        );
    }

    private function validated(BuildingModelOperationContext $context, bool $latest): ?StoredBuildingModel
    {
        return $this->store->transaction($context, function () use ($context, $latest): ?StoredBuildingModel {
            $stored = $latest ? $this->store->latest($context) : $this->store->find($context);
            if ($stored === null) {
                return null;
            }
            $ids = $this->store->evidenceIds($stored);
            if ($ids === []) {
                return null;
            }
            $nodes = $this->evidence->activeNodesForUpdate(
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $ids,
            );

            return array_map(static fn ($node): int => $node->id, $nodes) === $ids ? $stored : null;
        });
    }
}
