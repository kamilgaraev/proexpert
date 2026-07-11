<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;

final readonly class EloquentDocumentUnitExhaustionHandler implements DocumentUnitExhaustionHandler
{
    public function __construct(private DocumentProcessingStatusService $status) {}

    public function handle(int $unitId): void
    {
        $unit = EstimateGenerationProcessingUnit::query()->with('document')->find($unitId);

        if ($unit?->document === null || $unit->document->status === 'ignored') {
            return;
        }

        $this->status->markNeedsReview(
            $unit->document,
            0.0,
            ['document_unit_attempts_exhausted'],
            [],
            'unusable',
        );
    }
}
