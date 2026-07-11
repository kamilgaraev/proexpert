<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;

final readonly class RecoverExhaustedDocumentUnits
{
    public function __construct(private DocumentUnitExhaustionHandler $handler) {}

    public function handle(int $limit = 100): int
    {
        $ids = EstimateGenerationProcessingUnit::query()
            ->where('status', DocumentProcessingUnitStatus::Failed->value)
            ->where('attempt_count', '>=', ProcessDocumentUnit::MAX_ATTEMPTS)
            ->whereHas('document', static fn ($query) => $query->whereIn('status', ['queued', 'processing']))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $unitId) {
            $this->handler->handle((int) $unitId);
        }

        return $ids->count();
    }
}
