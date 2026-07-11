<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Integrations\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ImportedEstimateExampleExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\ImportSession;

final class EstimateLearningExampleExtractor implements ImportedEstimateExampleExtractor
{
    public function __construct(
        private readonly WorkIntentClassifier $workIntentClassifier,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractFromImportedEstimate(object $estimate, ?object $importSession = null): array
    {
        if (! $estimate instanceof Estimate || ($importSession !== null && ! $importSession instanceof ImportSession)) {
            return [];
        }

        if ($this->isAiGeneratedEstimate($estimate)) {
            return [];
        }

        $examples = [];

        $estimate->loadMissing([
            'items.section',
            'items.measurementUnit',
        ]);

        foreach ($estimate->items as $item) {
            $example = $this->fromEstimateItem($estimate, $item, $importSession);

            if ($example !== null) {
                $examples[] = $example;
            }
        }

        return $examples;
    }

    private function isAiGeneratedEstimate(Estimate $estimate): bool
    {
        $metadata = is_array($estimate->metadata) ? $estimate->metadata : [];

        return (bool) ($metadata['is_ai_generated'] ?? false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromEstimateItem(Estimate $estimate, EstimateItem $item, ?ImportSession $importSession): ?array
    {
        $workName = trim((string) $item->name);
        $normCode = $this->normalizeNormCode((string) $item->normative_rate_code);

        if ($workName === '' || $normCode === '' || $this->isResourceChild($item)) {
            return null;
        }

        $norm = $this->findNormByCode($normCode);

        if (! $norm instanceof EstimateNorm) {
            return null;
        }

        $workUnit = $this->workUnit($item);
        $qualityFlags = [];

        if (! $this->unitsCompatible($workUnit, (string) $norm->unit, $qualityFlags)) {
            return null;
        }

        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], [
            'section_title' => $item->section?->name,
        ]);

        return [
            'organization_id' => (int) $estimate->organization_id,
            'project_id' => $estimate->project_id !== null ? (int) $estimate->project_id : null,
            'source_type' => 'imported_estimate',
            'source_entity_type' => 'estimate_item',
            'source_entity_id' => (int) $item->id,
            'estimate_id' => (int) $estimate->id,
            'estimate_item_id' => (int) $item->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $item->quantity !== null ? (float) $item->quantity : null,
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => (int) $norm->id,
            'norm_code' => $normCode,
            'normative_name' => (string) $norm->name,
            'normative_unit' => (string) $norm->unit,
            'decision_status' => 'imported_selected',
            'confidence' => 0.9,
            'is_positive' => true,
            'source_quality_score' => 0.85,
            'context_payload' => [
                'estimate_name' => $estimate->name,
                'section_name' => $item->section?->name,
                'position_number' => $item->position_number,
                'raw_normative_rate_code' => $item->normative_rate_code,
                'import_session_id' => $importSession?->id,
                'import_file_name' => $importSession?->file_name,
            ],
            'source_refs' => [[
                'type' => 'estimate_item',
                'estimate_id' => (int) $estimate->id,
                'estimate_item_id' => (int) $item->id,
                'import_session_id' => $importSession?->id,
            ]],
            'quality_flags' => $qualityFlags,
            'accepted_at' => now(),
        ];
    }

    private function isResourceChild(EstimateItem $item): bool
    {
        $type = (string) ($item->item_type?->value ?? $item->item_type ?? '');

        return $item->parent_work_id !== null
            && in_array($type, ['material', 'equipment', 'machinery', 'labor'], true);
    }

    /**
     * @param  array<int, string>  $qualityFlags
     */
    private function unitsCompatible(?string $workUnit, string $normUnit, array &$qualityFlags): bool
    {
        if ($workUnit === null || trim($normUnit) === '') {
            $qualityFlags[] = 'unit_unverified';

            return true;
        }

        if (! NormativeUnitNormalizer::compatible($workUnit, $normUnit)) {
            $qualityFlags[] = 'unit_mismatch';

            return false;
        }

        $qualityFlags[] = 'unit_compatible';

        return true;
    }

    private function workUnit(EstimateItem $item): ?string
    {
        $unit = trim((string) ($item->measurementUnit?->short_name ?? $item->measurementUnit?->name ?? ''));

        return $unit !== '' ? $unit : null;
    }

    private function findNormByCode(string $code): ?EstimateNorm
    {
        return EstimateNorm::query()
            ->with('collection')
            ->whereIn('code', array_values(array_unique(array_filter([
                $code,
                preg_replace('/^[^\d]*/u', '', $code) ?? $code,
            ]))))
            ->latest('id')
            ->first();
    }

    private function normalizeNormCode(string $code): string
    {
        $code = trim($code);
        $code = preg_replace('/^[^\d]*/u', '', $code) ?? $code;
        $code = preg_replace('/\s+/u', '', $code) ?? $code;

        return trim($code);
    }

    /**
     * @return array<string, mixed>
     */
    private function intentPayload(WorkIntentData $intent): array
    {
        return [
            'scope' => $intent->scope,
            'action' => $intent->action,
            'object' => $intent->object,
            'material' => $intent->material,
            'system' => $intent->system,
            'expected_dimensions' => $intent->expectedDimensions,
            'signals' => $intent->signals,
            'confidence' => $intent->confidence,
        ];
    }
}
