<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use RuntimeException;

final readonly class EloquentSessionBaseInputVersionResolver implements SessionBaseInputVersionResolver
{
    public function __construct(private EvidenceAwarePipelineBaseInputVersionResolver $baseInputVersions) {}

    public function resolve(EstimateGenerationSession $session): string
    {
        $model = new EstimateGenerationSession;
        $model->setConnection($session->getConnectionName());
        $current = $model->newQuery()
            ->whereKey($session->getKey())
            ->lockForUpdate()
            ->first();
        if (! $current instanceof EstimateGenerationSession) {
            throw new RuntimeException('Estimate generation session is unavailable.');
        }

        return $this->baseInputVersions->fromSession($current);
    }
}
