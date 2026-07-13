<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use RuntimeException;

final readonly class EloquentSessionBaseInputVersionResolver implements SessionBaseInputVersionResolver
{
    public function resolve(EstimateGenerationSession $session): string
    {
        $model = new EstimateGenerationSession;
        $model->setConnection($session->getConnectionName());
        $current = $model->newQuery()
            ->whereKey($session->getKey())
            ->lockForUpdate()
            ->with([
                'documents.facts',
                'documents.drawingElements',
                'documents.quantityTakeoffs',
                'documents.scopeInferences',
            ])
            ->first();
        if (! $current instanceof EstimateGenerationSession) {
            throw new RuntimeException('Estimate generation session is unavailable.');
        }

        return PipelineBaseInputVersion::fromSession($current);
    }
}
