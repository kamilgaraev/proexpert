<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminBenchmarkDispatchCommand
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public int $actorId,
        public int $datasetId,
        public int $organizationId,
        public bool $confirmedAcceptance,
        public string $idempotencyKey,
        public array $manifest,
    ) {}

    public function fingerprint(): string
    {
        $manifest = $this->manifest;
        ksort($manifest);

        return 'sha256:'.hash('sha256', json_encode([
            'actor_id' => $this->actorId,
            'dataset_id' => $this->datasetId,
            'organization_id' => $this->organizationId,
            'confirmed_acceptance' => $this->confirmedAcceptance,
            'manifest' => $manifest,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
