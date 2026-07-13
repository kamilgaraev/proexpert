<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminTrainingDatasetActionCommand
{
    public function __construct(
        public int $actorId,
        public int $datasetId,
        public int $organizationId,
        public int $expectedVersion,
        public string $action,
        public string $idempotencyKey,
    ) {}

    public function fingerprint(): string
    {
        return 'sha256:'.hash('sha256', implode('|', [
            'dataset_action',
            "actor_id={$this->actorId}",
            "dataset_id={$this->datasetId}",
            "organization_id={$this->organizationId}",
            "expected_version={$this->expectedVersion}",
            "action={$this->action}",
        ]));
    }
}
