<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\MissingRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\PriceSnapshotData;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveUnitConversion;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Log;

class EstimatePricingService
{
    private ResolveRegionalPrice $regionalPrice;

    private ResolveUnitConversion $unitConversion;

    private ?AcceptedQuantityEvidenceVerifier $acceptedEvidence;

    public function __construct(
        ?ResolveRegionalPrice $regionalPrice = null,
        ?ResolveUnitConversion $unitConversion = null,
        ?AcceptedQuantityEvidenceVerifier $acceptedEvidence = null,
    ) {
        $this->regionalPrice = $regionalPrice ?? new ResolveRegionalPrice;
        $this->unitConversion = $unitConversion ?? new ResolveUnitConversion;
        $this->acceptedEvidence = $acceptedEvidence;
    }

    public function price(array $workItems, array $regionalContext = [], ?PipelineContext $context = null): array
    {
        foreach ($workItems as &$workItem) {
            if ((string) ($workItem['item_type'] ?? 'priced_work') === 'quantity_review') {
                $workItem['materials'] = [];
                $workItem['labor'] = [];
                $workItem['machinery'] = [];
                $workItem['other_resources'] = [];
                $workItem['work_cost'] = 0;
                $workItem['materials_cost'] = 0;
                $workItem['machinery_cost'] = 0;
                $workItem['labor_cost'] = 0;
                $workItem['total_cost'] = 0;
                $workItem['price_source'] = null;
                $workItem['price_snapshot'] = null;
                $workItem['pricing_status'] = 'not_calculated';
                $workItem['pricing_blocker'] = 'quantity_review_required';

                continue;
            }

            if (in_array((string) ($workItem['item_type'] ?? 'priced_work'), ['operation', 'resource_note', 'review_note'], true)) {
                $workItem['work_cost'] = 0;
                $workItem['materials_cost'] = 0;
                $workItem['machinery_cost'] = 0;
                $workItem['labor_cost'] = 0;
                $workItem['total_cost'] = 0;
                $workItem['price_snapshot'] = null;

                continue;
            }

            try {
                [$workItem, $resourceSnapshots, $costs] = $this->resolveResources($workItem, $regionalContext);
                $materialsCost = $costs['materials'];
                $laborCost = $costs['labor'];
                $machineryCost = $costs['machinery'];
                $otherCost = $costs['other_resources'];
                $workCost = BigDecimal::zero()->toScale(2);
                $total = $materialsCost->plus($laborCost)->plus($machineryCost)->plus($otherCost)->plus($workCost)->toScale(2, RoundingMode::HalfUp);
                $workItem['work_cost'] = (string) $workCost;
                $workItem['materials_cost'] = (string) $materialsCost->toScale(2, RoundingMode::HalfUp);
                $workItem['machinery_cost'] = (string) $machineryCost->toScale(2, RoundingMode::HalfUp);
                $workItem['labor_cost'] = (string) $laborCost->toScale(2, RoundingMode::HalfUp);
                $workItem['total_cost'] = (string) $total;
                $workItem['price_snapshot'] = $this->snapshot($resourceSnapshots, $workCost, $total)->toArray();
                if ($context !== null && $this->acceptedEvidence?->verify($context, $workItem) !== true) {
                    $this->blockQuantityEvidence($workItem);

                    continue;
                }
                if ($context !== null) {
                    $workItem['pricing_finalized_at'] = $workItem['price_snapshot']['captured_at'];
                }
            } catch (MissingRegionalPrice $exception) {
                Log::warning('estimate_generation.price_snapshot_rejected', [
                    'work_key' => $workItem['key'] ?? null,
                    'work_name' => $workItem['name'] ?? null,
                    'norm_code' => $workItem['normative_match']['code'] ?? null,
                    'price_id' => $exception->priceId,
                    'reason' => $exception->reason,
                    ...$exception->context,
                ]);
                $this->blockMissingSnapshot($workItem);

                continue;
            } catch (\Brick\Math\Exception\MathException) {
                $this->blockMissingSnapshot($workItem);

                continue;
            }

            if ($total->isLessThanOrEqualTo(0)) {
                $workItem['pricing_status'] = 'not_calculated';
                $workItem['pricing_blocker'] = $workItem['pricing_blocker'] ?? 'pricing_not_calculated';
                $workItem['validation_flags'] = array_values(array_unique([
                    ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
                    'pricing_not_calculated',
                ]));
            }
        }

        return $workItems;
    }

    public function quantityEvidenceRejectionReason(PipelineContext $context, array $workItem): ?string
    {
        return $this->acceptedEvidence?->rejectionReason(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            (string) $context->baseInputVersion,
            $workItem,
        );
    }

    private function resolveResources(array $workItem, array $regionalContext): array
    {
        $resourceSnapshots = [];
        $costs = [];
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            $costs[$group] = BigDecimal::zero();
            foreach ($workItem[$group] ?? [] as $index => $resource) {
                if (! is_array($resource)) {
                    throw MissingRegionalPrice::forResource(0, 'resource_payload_invalid');
                }
                $fromUnit = trim((string) ($resource['unit'] ?? ''));
                $projectSelection = $this->projectResourceSelection($resource, $workItem);
                if ($this->isResidentialConvertedSelection($projectSelection)) {
                    $resource['project_resource_selection'] = $projectSelection;
                    $resource['price_unit'] = $fromUnit;
                    $resource['normative_ref'] = [
                        ...(is_array($resource['normative_ref'] ?? null) ? $resource['normative_ref'] : []),
                        'project_resource_selection' => $projectSelection,
                    ];
                    $workItem[$group][$index] = $resource;
                }
                $toUnit = trim((string) ($resource['price_unit'] ?? $resource['pricing']['unit'] ?? $fromUnit));
                if ($fromUnit !== $toUnit
                    && trim((string) ($workItem['key'] ?? '')) !== ''
                    && ! $this->isResidentialConvertedSelection($projectSelection)) {
                    Log::info('estimate_generation.unit_conversion_selection_context', [
                        'work_key' => $workItem['key'],
                        'norm_code' => $workItem['normative_match']['code'] ?? null,
                        'group_code' => $resource['normative_ref']['resource_code'] ?? null,
                        'price_id' => $resource['normative_ref']['price_id'] ?? $resource['price_id'] ?? null,
                        'unit_price' => $resource['unit_price'] ?? null,
                        'from_unit' => $fromUnit,
                        'to_unit' => $toUnit,
                        'selected_resource_context' => $projectSelection,
                        'decision_resource_contexts' => $workItem['normative_match']['project_resource_selections'] ?? [],
                    ]);
                }
                $version = (int) ($resource['unit_conversion_version'] ?? $regionalContext['unit_conversion_version'] ?? 1);
                $conversion = $this->hasResidentialConvertedPrice($resource)
                    ? null
                    : $this->unitConversion->handle($fromUnit, $toUnit, $version);
                if ($conversion !== null) {
                    $resource['quantity'] = (string) BigDecimal::of((string) ($resource['quantity'] ?? '0'))
                        ->multipliedBy($conversion->factor);
                    $resource['normative_ref'] = [
                        ...(is_array($resource['normative_ref'] ?? null) ? $resource['normative_ref'] : []),
                        'unit_conversion_id' => $conversion->id,
                        'unit_conversion_factor' => $conversion->factor,
                        'unit_conversion_version' => $conversion->version,
                        'unit_conversion_fingerprint' => $conversion->fingerprint,
                    ];
                    $workItem[$group][$index] = $resource;
                }
                $snapshot = $this->regionalPrice->handle($resource, $regionalContext)->toArray();
                $resourceSnapshots[] = $snapshot;
                $costs[$group] = $costs[$group]->plus($snapshot['final_amount']);
                $workItem[$group][$index]['unit_price'] = $snapshot['base_amount'];
                $workItem[$group][$index]['total_price'] = $snapshot['final_amount'];
            }
        }
        if ($resourceSnapshots === []) {
            throw MissingRegionalPrice::forResource(0, 'resource_snapshots_empty');
        }

        return [$workItem, $resourceSnapshots, $costs];
    }

    private function hasResidentialConvertedPrice(array $resource): bool
    {
        return $this->isResidentialConvertedSelection($this->projectResourceSelection($resource));
    }

    private function projectResourceSelection(array $resource, array $workItem = []): array
    {
        $resourceSelection = is_array($resource['project_resource_selection'] ?? null)
            ? $resource['project_resource_selection']
            : (is_array($resource['normative_ref']['project_resource_selection'] ?? null)
                ? $resource['normative_ref']['project_resource_selection']
                : []);
        if ($this->isResidentialConvertedSelection($resourceSelection)) {
            return $resourceSelection;
        }

        $groupCode = trim((string) ($resource['normative_ref']['resource_code'] ?? ''));
        $priceId = (int) ($resource['normative_ref']['price_id'] ?? $resource['price_id'] ?? 0);
        $unitPrice = (string) ($resource['unit_price'] ?? '0');
        $resourceUnit = trim((string) ($resource['unit'] ?? ''));
        $selections = is_array($workItem['normative_match']['project_resource_selections'] ?? null)
            ? $workItem['normative_match']['project_resource_selections']
            : [];
        $matchedDecisionSelection = [];
        foreach ($selections as $selection) {
            if (! is_array($selection)
                || trim((string) ($selection['group_code'] ?? '')) !== $groupCode
                || (int) ($selection['price_id'] ?? 0) !== $priceId
                || ! NormativeUnitNormalizer::compatible(
                    trim((string) ($selection['price_unit'] ?? '')),
                    $resourceUnit,
                )
                || ! $this->sameDecimal($selection['applied_unit_price'] ?? null, $unitPrice)) {
                continue;
            }

            $matchedDecisionSelection = $selection;
            if ($this->isResidentialConvertedSelection($selection)) {
                return $selection;
            }
        }

        return $resourceSelection !== [] ? $resourceSelection : $matchedDecisionSelection;
    }

    private function isResidentialConvertedSelection(array $selection): bool
    {
        return str_contains((string) ($selection['policy'] ?? ''), '_residential_converted_');
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        try {
            return BigDecimal::of((string) $left)->isEqualTo(BigDecimal::of((string) $right));
        } catch (\Throwable) {
            return false;
        }
    }

    private function snapshot(array $resourceSnapshots, BigDecimal $workCost, BigDecimal $total): PriceSnapshotData
    {

        $first = $resourceSnapshots[0];
        foreach ($resourceSnapshots as $snapshot) {
            if ($snapshot['region_id'] !== $first['region_id']
                || $snapshot['zone_id'] !== $first['zone_id']
                || $snapshot['period_id'] !== $first['period_id']
                || $snapshot['version_id'] !== $first['version_id']
                || $snapshot['currency'] !== $first['currency']) {
                throw MissingRegionalPrice::forResource(0, 'resource_snapshot_context_mismatch');
            }
        }

        $base = BigDecimal::zero();
        foreach ($resourceSnapshots as $resourceSnapshot) {
            $base = $base->plus($resourceSnapshot['final_amount']);
        }
        $manifest = self::canonicalEvidenceManifest($resourceSnapshots);

        return new PriceSnapshotData(
            regionId: $first['region_id'],
            zoneId: $first['zone_id'],
            periodId: $first['period_id'],
            versionId: $first['version_id'],
            sourceType: 'regional_resource_aggregate',
            sourceReference: 'sha256:'.hash('sha256', $manifest),
            baseAmount: (string) $base->toScale(2, RoundingMode::HalfUp),
            coefficients: [
                'work_cost' => (string) $workCost->toScale(2, RoundingMode::HalfUp),
                'resource_evidence' => $resourceSnapshots,
            ],
            finalAmount: (string) $total->toScale(2, RoundingMode::HalfUp),
            currency: $first['currency'],
            capturedAt: now()->toIso8601String(),
        );
    }

    private static function canonicalEvidenceManifest(array $resourceSnapshots): string
    {
        $references = array_column($resourceSnapshots, 'source_reference');
        sort($references, SORT_STRING);

        return implode('|', $references);
    }

    private function blockMissingSnapshot(array &$workItem): void
    {
        $workItem['work_cost'] = 0;
        $workItem['materials_cost'] = 0;
        $workItem['machinery_cost'] = 0;
        $workItem['labor_cost'] = 0;
        $workItem['total_cost'] = 0;
        $workItem['price_source'] = null;
        $workItem['price_snapshot'] = null;
        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] ??= 'missing_price_snapshot';
        $workItem['validation_flags'] = array_values(array_unique([
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            'missing_price_snapshot',
        ]));
    }

    private function blockQuantityEvidence(array &$workItem): void
    {
        $this->blockMissingSnapshot($workItem);
        $workItem['pricing_blocker'] = 'quantity_evidence_not_accepted';
        $workItem['pricing_finalized_at'] = null;
        $workItem['validation_flags'] = array_values(array_unique([
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            'quantity_evidence_not_accepted',
        ]));
    }
}
