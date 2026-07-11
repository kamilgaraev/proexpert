<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use InvalidArgumentException;

final readonly class BuildingModelRepository
{
    public function __construct(private BuildingModelStore $store, private EvidenceRepository $evidence) {}

    public function store(BuildingModelOperationContext $context, NormalizedBuildingModelData $model): StoredBuildingModel
    {
        return $this->store->transaction($context, function () use ($context, $model): StoredBuildingModel {
            foreach ($model->evidenceIds as $evidenceId) {
                $node = $this->evidence->node($context->organizationId, $context->projectId, $context->sessionId, $evidenceId);
                if ($node === null || $node->invalidatedAt !== null) {
                    throw new InvalidArgumentException('Building model evidence must be active and match the exact tenant scope.');
                }
            }
            $stored = $this->store->insertOrGet($context, $model);
            $this->store->attachEvidence($stored, $model->evidenceIds);

            return $stored;
        });
    }
}
