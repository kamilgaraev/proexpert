<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;

final readonly class EloquentDocumentUnitExhaustionHandler implements DocumentUnitExhaustionHandler
{
    public function __construct(private RequireDocumentProcessingReview $review) {}

    public function handle(int $unitId): void
    {
        $unit = EstimateGenerationProcessingUnit::query()->with('document')->find($unitId);

        if ($unit?->document === null || $unit->document->status === 'ignored') {
            return;
        }

        $this->review->handle(
            (int) $unit->document->getKey(),
            (string) $unit->source_version,
            (string) $unit->source_version,
            ['document_unit_attempts_exhausted'],
        );
    }
}
