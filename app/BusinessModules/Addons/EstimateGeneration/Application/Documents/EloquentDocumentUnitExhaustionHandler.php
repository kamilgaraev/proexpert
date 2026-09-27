<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;

final readonly class EloquentDocumentUnitExhaustionHandler implements DocumentUnitExhaustionHandler
{
    public function __construct(private DocumentUnitAggregateReconciler $reconciler) {}

    public function handle(int $unitId): void
    {
        $unit = EstimateGenerationProcessingUnit::query()->with('document')->find($unitId);

        if ($unit?->document === null || $unit->document->status === 'ignored') {
            return;
        }

        if ($unit->status === DocumentProcessingUnitStatus::Failed
            && $unit->page !== null
            && $unit->page->status !== 'excluded'
            && $unit->page->status !== 'failed') {
            $unit->page->forceFill(['status' => 'failed'])->save();
        }

        $this->reconciler->reconcile(
            (int) $unit->document->getKey(),
            (string) $unit->source_version,
        );
    }
}
