<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class EstimateGenerationPackagePersistenceService
{
    public function __construct(
        private readonly EstimateGenerationNoAirWorkItemPolicy $noAirWorkItemPolicy = new EstimateGenerationNoAirWorkItemPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     */
    public function syncFromDraft(EstimateGenerationSession $session, array $draft): void
    {
        DB::transaction(function () use ($session, $draft): void {
            $activePackageKeys = $this->draftPackageKeys($draft);

            foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
                if (! is_array($localEstimate)) {
                    continue;
                }

                $this->syncLocalEstimate($session, $localEstimate, (int) $localIndex);
            }

            $this->retainHistoricalPackages($session, $activePackageKeys);
        });
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function syncWorkItemPackageFromDraft(EstimateGenerationSession $session, array $draft, string $workItemKey): bool
    {
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            if (! is_array($localEstimate) || ! $this->localEstimateContainsWorkItem($localEstimate, $workItemKey)) {
                continue;
            }

            DB::transaction(function () use ($session, $localEstimate, $localIndex): void {
                $this->syncLocalEstimate($session, $localEstimate, (int) $localIndex);
            });

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    private function draftPackageKeys(array $draft): array
    {
        $keys = [];

        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            $keys[] = $this->packageKey($localEstimate, (int) $localIndex);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     */
    private function packageKey(array $localEstimate, int $localIndex): string
    {
        return (string) ($localEstimate['key'] ?? 'package-'.($localIndex + 1));
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     */
    private function syncLocalEstimate(EstimateGenerationSession $session, array $localEstimate, int $localIndex): void
    {
        $workItems = $this->estimateWorkItems($this->workItems($localEstimate));
        $quality = $this->packageQuality($localEstimate, $workItems);
        $itemCounters = $this->itemCounters($workItems);
        $totalCost = $this->workItemsTotal($workItems);
        $packageKey = $this->packageKey($localEstimate, $localIndex);
        $package = EstimateGenerationPackage::query()->updateOrCreate(
            [
                'session_id' => $session->id,
                'key' => $packageKey,
            ],
            [
                'input_version' => $this->packageInputVersion($session, $localEstimate),
                'title' => (string) ($localEstimate['title'] ?? 'Локальная смета'),
                'scope_type' => (string) ($localEstimate['scope_type'] ?? 'custom'),
                'status' => $this->packageStatus($quality),
                'generation_stage' => 'quality_check',
                'generation_progress' => 100,
                'target_items_min' => (int) ($localEstimate['target_items_min'] ?? 0),
                'target_items_max' => (int) ($localEstimate['target_items_max'] ?? 0),
                'actual_items_count' => $itemCounters['total_items_count'],
                'totals' => [
                    'total_cost' => $totalCost,
                    ...$itemCounters,
                ],
                'quality_summary' => $quality,
                'assumptions' => $localEstimate['assumptions'] ?? [],
                'source_refs' => $localEstimate['source_refs'] ?? [],
                'metadata' => [
                    'generated_from' => 'estimate_generation_v2',
                    'input_version' => $this->packageInputVersion($session, $localEstimate),
                ],
                'sort_order' => ($localIndex + 1) * 100,
                'finished_at' => now(),
                'failed_at' => null,
                'cancelled_at' => null,
                'last_error_code' => null,
            ]
        );

        foreach ($workItems as $workIndex => $workItem) {
            $this->appendItemRevision($package, $workItem, $workIndex);
        }

        $this->refreshPackagePricingState($package);
    }

    private function refreshPackagePricingState(EstimateGenerationPackage $package): void
    {
        $latest = EstimateGenerationPackageItem::query()->where('package_id', $package->id)
            ->orderBy('logical_key')->orderByDesc('revision')->orderByDesc('id')->get()
            ->unique(fn (EstimateGenerationPackageItem $item): string => (string) ($item->logical_key ?? $item->key));
        $priced = $latest->filter(fn (EstimateGenerationPackageItem $item): bool => $item->item_type === 'priced_work' && $item->pricing_finalized_at !== null);
        $unfinalized = $latest->contains(fn (EstimateGenerationPackageItem $item): bool => $item->item_type === 'priced_work' && $item->pricing_finalized_at === null);
        $total = $priced->reduce(
            fn (BigDecimal $sum, EstimateGenerationPackageItem $item): BigDecimal => $sum->plus((string) $item->total_cost),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HalfUp);
        $quality = is_array($package->quality_summary) ? $package->quality_summary : [];
        $criticalFlags = array_values(array_filter(
            (array) ($quality['critical_flags'] ?? []),
            static fn (mixed $flag): bool => $flag !== 'missing_price_snapshot',
        ));
        if ($unfinalized) {
            $criticalFlags[] = 'missing_price_snapshot';
        }
        $quality['critical_flags'] = array_values(array_unique($criticalFlags));
        $quality['level'] = $unfinalized
            ? 'blocked'
            : ($quality['critical_flags'] === [] ? 'passed' : 'review_required');
        $totals = is_array($package->totals) ? $package->totals : [];
        $totals['total_cost'] = (string) $total;
        $totals['priced_items_count'] = $priced->count();
        $totals['total_items_count'] = $latest->count();
        $totals['items_count'] = $latest->count();
        $package->forceFill([
            'status' => $this->packageStatus($quality),
            'generation_progress' => $unfinalized ? 99 : 100,
            'finished_at' => $unfinalized ? null : now(),
            'actual_items_count' => $latest->count(),
            'quality_summary' => $quality,
            'totals' => $totals,
        ])->save();
    }

    private function appendItemRevision(EstimateGenerationPackage $package, array $workItem, int $index): void
    {
        EstimateGenerationPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
        $logicalKey = (string) ($workItem['key'] ?? $package->key.'.item.'.($index + 1));
        $latest = EstimateGenerationPackageItem::query()
            ->where('package_id', $package->id)
            ->where(fn ($query) => $query->where('logical_key', $logicalKey)->orWhere(fn ($legacy) => $legacy->whereNull('logical_key')->where('key', $logicalKey)))
            ->orderByDesc('revision')->orderByDesc('id')->lockForUpdate()->first();
        $revision = max(1, (int) ($latest?->revision ?? 0) + 1);
        $pricing = $this->authoritativePricing($package, $workItem, $logicalKey);
        if ($latest !== null && $pricing !== null && $this->samePricingIdentity($latest, $pricing)) {
            return;
        }
        $payload = $this->itemPayload($package, $workItem, $index);
        if ((string) ($workItem['item_type'] ?? 'priced_work') === 'priced_work') {
            $payload = array_replace($payload, [
                'price_snapshot' => null, 'price_source' => null, 'unit_price' => '0.000000',
                'direct_cost' => '0.00', 'overhead_cost' => '0.00', 'profit_cost' => '0.00', 'total_cost' => '0.00',
            ]);
        }
        if ($pricing !== null) {
            $payload = array_replace($payload, $pricing['item']);
        }
        $payload['logical_key'] = $logicalKey;
        $payload['revision'] = $revision;
        $payload['supersedes_item_id'] = $latest?->id;
        $payload['key'] = $logicalKey.'#r'.$revision;

        if ($latest !== null && $this->revisionFingerprint($latest->getAttributes()) === $this->revisionFingerprint($payload)) {
            return;
        }

        $item = EstimateGenerationPackageItem::query()->create($payload);
        if ($pricing === null) {
            return;
        }
        foreach ($pricing['inputs'] as $ordinal => $input) {
            DB::table('estimate_generation_package_item_price_inputs')->insert([
                ...$input,
                'package_item_id' => $item->id,
                'ordinal' => $ordinal + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::select('SELECT public.eg_finalize_package_item_price(?)', [$item->id]);
    }

    /** @param array{item: array<string, mixed>, inputs: list<array<string, int|null>>} $pricing */
    private function samePricingIdentity(EstimateGenerationPackageItem $latest, array $pricing): bool
    {
        foreach (['quantity_evidence_id', 'quantity_evidence_fingerprint', 'estimate_norm_id', 'region_id', 'price_zone_id', 'period_id', 'regional_price_version_id'] as $column) {
            if ((string) $latest->getAttribute($column) !== (string) ($pricing['item'][$column] ?? null)) {
                return false;
            }
        }
        $stored = DB::table('estimate_generation_package_item_price_inputs')->where('package_item_id', $latest->id)
            ->orderBy('ordinal')->get(['norm_resource_id', 'resource_price_id', 'unit_conversion_id'])
            ->map(static fn (object $input): array => [
                'norm_resource_id' => (int) $input->norm_resource_id,
                'resource_price_id' => (int) $input->resource_price_id,
                'unit_conversion_id' => $input->unit_conversion_id === null ? null : (int) $input->unit_conversion_id,
            ])->all();

        return $stored === $pricing['inputs'];
    }

    /** @return array{item: array<string, mixed>, inputs: list<array<string, int|null>>}|null */
    private function authoritativePricing(EstimateGenerationPackage $package, array $workItem, string $logicalKey): ?array
    {
        if ((string) ($workItem['item_type'] ?? 'priced_work') !== 'priced_work') {
            return null;
        }
        $snapshot = is_array($workItem['price_snapshot'] ?? null) ? $workItem['price_snapshot'] : [];
        $normId = $this->positiveInt($workItem['normative_match']['norm_id'] ?? null);
        $context = [
            'region_id' => $this->positiveInt($snapshot['region_id'] ?? null),
            'price_zone_id' => $this->positiveInt($snapshot['zone_id'] ?? null),
            'period_id' => $this->positiveInt($snapshot['period_id'] ?? null),
            'regional_price_version_id' => $this->positiveInt($snapshot['version_id'] ?? null),
        ];
        $inputs = [];
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            foreach ($workItem[$group] ?? [] as $resource) {
                $reference = is_array($resource['normative_ref'] ?? null) ? $resource['normative_ref'] : [];
                $normResourceId = $this->positiveInt($reference['norm_resource_id'] ?? null);
                $priceId = $this->positiveInt($reference['price_id'] ?? null);
                if ($normResourceId === null || $priceId === null) {
                    return null;
                }
                $inputs[] = [
                    'norm_resource_id' => $normResourceId,
                    'resource_price_id' => $priceId,
                    'unit_conversion_id' => $this->positiveInt($reference['unit_conversion_id'] ?? null),
                ];
            }
        }
        if ($normId === null || in_array(null, $context, true) || $inputs === []) {
            return null;
        }
        $quantity = $this->positiveDecimal($workItem['quantity'] ?? null);
        $unit = $workItem['unit'] ?? null;
        if ($quantity === null || ! is_string($unit) || $unit === '') {
            return null;
        }
        $session = $package->session()->firstOrFail();
        $evidenceId = $this->positiveInt($workItem['quantity_evidence_id'] ?? null);
        $evidenceFingerprint = $workItem['quantity_evidence_fingerprint'] ?? null;
        if ($evidenceId === null || ! is_string($evidenceFingerprint)) {
            return null;
        }
        $evidence = app(EvidenceRepository::class)->node((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $evidenceId);
        if ($evidence === null || $evidence->fingerprint !== $evidenceFingerprint || $evidence->invalidatedAt !== null
            || $this->positiveDecimal($evidence->value['quantity'] ?? null)?->compareTo($quantity) !== 0
            || ($evidence->value['unit'] ?? null) !== $unit) {
            return null;
        }

        return ['item' => [
            'price_snapshot' => null,
            'price_source' => null,
            'quantity' => null,
            'unit_price' => '0.000000',
            'direct_cost' => '0.00',
            'overhead_cost' => '0.00',
            'profit_cost' => '0.00',
            'total_cost' => '0.00',
            'quantity_evidence_id' => $evidenceId,
            'quantity_evidence_fingerprint' => $evidenceFingerprint,
            'estimate_norm_id' => $normId,
            ...$context,
        ], 'inputs' => $inputs];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function positiveDecimal(mixed $value): ?BigDecimal
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        try {
            $decimal = BigDecimal::of((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return $decimal->isGreaterThan(0) ? $decimal : null;
    }

    private function revisionFingerprint(array $payload): string
    {
        unset($payload['id'], $payload['key'], $payload['revision'], $payload['supersedes_item_id'], $payload['created_at'], $payload['updated_at']);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function packageInputVersion(EstimateGenerationSession $session, array $localEstimate): ?string
    {
        $version = $localEstimate['input_version'] ?? $session->input_payload['input_version'] ?? null;

        return is_string($version) && preg_match('/^sha256:[a-f0-9]{64}$/', $version) === 1 ? $version : null;
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     */
    private function localEstimateContainsWorkItem(array $localEstimate, string $workItemKey): bool
    {
        foreach ($localEstimate['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach ($section['work_items'] ?? [] as $workItem) {
                if (is_array($workItem) && (string) ($workItem['key'] ?? '') === $workItemKey) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $activePackageKeys
     */
    private function retainHistoricalPackages(EstimateGenerationSession $session, array $activePackageKeys): void
    {
        $query = EstimateGenerationPackage::query()
            ->where('session_id', $session->id);

        if ($activePackageKeys !== []) {
            $query->whereNotIn('key', $activePackageKeys);
        }

        $query->update(['status' => 'superseded']);
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     * @return array<int, array<string, mixed>>
     */
    private function workItems(array $localEstimate): array
    {
        $items = [];

        foreach ($localEstimate['sections'] ?? [] as $section) {
            foreach ($section['work_items'] ?? [] as $workItem) {
                if (is_array($workItem)) {
                    $items[] = $workItem;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $workItems
     * @return array<int, array<string, mixed>>
     */
    private function estimateWorkItems(array $workItems): array
    {
        $workItems = array_values(array_filter(
            $workItems,
            fn (array $workItem): bool => $this->isEstimateWorkItem($workItem)
        ));

        return array_map(
            fn (array $workItem): array => $this->noAirWorkItemPolicy->requiresReview($workItem)
                ? $this->noAirWorkItemPolicy->markRequiresReview($workItem)
                : $workItem,
            $workItems
        );
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function isEstimateWorkItem(array $workItem): bool
    {
        return ! in_array((string) ($workItem['item_type'] ?? 'priced_work'), EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     * @param  array<int, array<string, mixed>>  $workItems
     * @return array<string, mixed>
     */
    private function packageQuality(array $localEstimate, array $workItems): array
    {
        $critical = [];
        $warnings = [];

        $targetItemsMin = (int) ($localEstimate['target_items_min'] ?? 0);
        $counters = $this->itemCounters($workItems);
        $pricedTargetMin = min(10, max(3, (int) ceil(max($targetItemsMin, 1) / 7)));

        if ($counters['priced_items_count'] < $pricedTargetMin && $counters['quantity_review_items_count'] === 0) {
            $critical[] = 'insufficient_detail';
        } elseif ($counters['quantity_review_items_count'] > 0) {
            $warnings[] = 'quantity_review_required';
        }

        foreach ($workItems as $workItem) {
            foreach ($workItem['validation_flags'] ?? [] as $flag) {
                if (in_array($flag, ['missing_price', 'missing_resources'], true)) {
                    $critical[] = (string) $flag;

                    continue;
                }

                if (in_array($flag, [
                    EstimateGenerationNoAirWorkItemPolicy::FLAG,
                    EstimateGenerationNoAirWorkItemPolicy::NO_AIR_FLAG,
                    'pricing_not_calculated',
                    'safe_norm_required',
                ], true)) {
                    $critical[] = (string) $flag;

                    continue;
                }

                $warnings[] = (string) $flag;
            }
        }

        $critical = array_values(array_unique($critical));
        $warnings = array_values(array_unique($warnings));

        return [
            'level' => $critical === [] ? 'passed' : 'review_required',
            'critical_flags' => $critical,
            'warning_flags' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $quality
     */
    private function packageStatus(array $quality): string
    {
        return match ((string) ($quality['level'] ?? 'review_required')) {
            'passed' => 'ready_for_review',
            'blocked' => 'blocked',
            default => 'review_required',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $workItems
     * @return array<string, int>
     */
    private function itemCounters(array $workItems): array
    {
        $priced = 0;
        $quantityReviews = 0;

        foreach ($workItems as $workItem) {
            $type = (string) ($workItem['item_type'] ?? 'priced_work');

            if ($type === EstimateGenerationPackageItem::QUANTITY_REVIEW_ITEM_TYPE) {
                $quantityReviews++;

                continue;
            }

            if (in_array($type, EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true)) {
                continue;
            }

            $priced++;
        }

        $visibleItems = $priced + $quantityReviews;

        return [
            'items_count' => $visibleItems,
            'total_items_count' => $visibleItems,
            'priced_items_count' => $priced,
            'quantity_review_items_count' => $quantityReviews,
            'operation_items_count' => 0,
            'review_notes_count' => 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $workItems
     */
    private function workItemsTotal(array $workItems): string
    {
        $total = BigDecimal::zero();
        foreach ($workItems as $workItem) {
            $total = $total->plus((string) ($workItem['total_cost'] ?? '0'));
        }

        return (string) $total->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function itemPayload(EstimateGenerationPackage $package, array $workItem, int $index): array
    {
        $workComposition = $this->workComposition($workItem);

        return [
            'package_id' => $package->id,
            'key' => (string) ($workItem['key'] ?? $package->key.'.item.'.($index + 1)),
            'parent_key' => $workItem['parent_key'] ?? null,
            'level' => (int) ($workItem['level'] ?? 0),
            'item_type' => (string) ($workItem['item_type'] ?? 'work'),
            'name' => (string) ($workItem['name'] ?? 'Работа'),
            'unit' => $workItem['unit'] ?? null,
            'quantity' => isset($workItem['quantity']) ? (string) BigDecimal::of((string) $workItem['quantity']) : null,
            'quantity_basis' => [
                'description' => $workItem['quantity_basis'] ?? null,
                'formula' => $workItem['quantity_formula'] ?? null,
            ],
            'price_source' => $workItem['price_source'] ?? null,
            'price_snapshot' => $workItem['price_snapshot'] ?? null,
            'normative_status' => $workItem['normative_match']['status'] ?? null,
            'normative_confidence' => isset($workItem['normative_match']['confidence'])
                ? (float) $workItem['normative_match']['confidence']
                : null,
            'unit_price' => $this->unitPrice($workItem),
            'direct_cost' => (string) BigDecimal::of((string) ($workItem['materials_cost'] ?? '0'))
                ->plus((string) ($workItem['labor_cost'] ?? '0'))
                ->plus((string) ($workItem['machinery_cost'] ?? '0'))
                ->toScale(2, RoundingMode::HalfUp),
            'overhead_cost' => '0.00',
            'profit_cost' => '0.00',
            'total_cost' => (string) ($workItem['total_cost'] ?? '0.00'),
            'resources' => [
                'materials' => $workItem['materials'] ?? [],
                'labor' => $workItem['labor'] ?? [],
                'machinery' => $workItem['machinery'] ?? [],
                'other' => $workItem['other_resources'] ?? [],
            ],
            'flags' => $workItem['validation_flags'] ?? [],
            'metadata' => [
                'normative_match' => $workItem['normative_match'] ?? null,
                'normative_candidates' => $workItem['normative_candidates'] ?? [],
                'pricing_status' => $workItem['pricing_status'] ?? null,
                'pricing_blocker' => $workItem['pricing_blocker'] ?? null,
                'pricing_blocker_message' => $workItem['pricing_blocker_message'] ?? null,
                'source_refs' => $workItem['source_refs'] ?? [],
                'work_category' => $workItem['work_category'] ?? null,
                'confidence' => $workItem['confidence'] ?? null,
                ...($workItem['metadata'] ?? []),
                'work_composition' => $workComposition,
                'composition_items_count' => count($workComposition),
            ],
            'sort_order' => ($index + 1) * 100,
        ];
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<int, string>
     */
    private function workComposition(array $workItem): array
    {
        $composition = $workItem['work_composition']
            ?? $workItem['metadata']['work_composition']
            ?? $workItem['normative_match']['work_composition']
            ?? [];

        if (! is_array($composition)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $composition),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function unitPrice(array $workItem): string
    {
        $quantity = BigDecimal::of((string) ($workItem['quantity'] ?? '0'));

        if ($quantity->isLessThanOrEqualTo(0)) {
            return '0.000000';
        }

        return (string) BigDecimal::of((string) ($workItem['total_cost'] ?? '0'))
            ->dividedBy($quantity, 6, RoundingMode::HalfUp);
    }
}
