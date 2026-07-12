<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
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

            $this->deleteStalePackages($session, $activePackageKeys);
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

        EstimateGenerationPackageItem::query()
            ->where('package_id', $package->id)
            ->delete();

        foreach ($workItems as $workIndex => $workItem) {
            EstimateGenerationPackageItem::query()->create($this->itemPayload($package, $workItem, $workIndex));
        }
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
    private function deleteStalePackages(EstimateGenerationSession $session, array $activePackageKeys): void
    {
        $query = EstimateGenerationPackage::query()
            ->where('session_id', $session->id);

        if ($activePackageKeys !== []) {
            $query->whereNotIn('key', $activePackageKeys);
        }

        $stalePackageIds = array_values(array_map('intval', $query->pluck('id')->all()));

        if ($stalePackageIds === []) {
            return;
        }

        EstimateGenerationPackageItem::query()
            ->whereIn('package_id', $stalePackageIds)
            ->delete();

        EstimateGenerationPackage::query()
            ->whereIn('id', $stalePackageIds)
            ->delete();
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
    private function workItemsTotal(array $workItems): float
    {
        return round(array_sum(array_map(
            static fn (array $workItem): float => (float) ($workItem['total_cost'] ?? 0),
            $workItems
        )), 2);
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
            'quantity' => isset($workItem['quantity']) ? (float) $workItem['quantity'] : null,
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
            'direct_cost' => (float) ($workItem['materials_cost'] ?? 0) + (float) ($workItem['labor_cost'] ?? 0) + (float) ($workItem['machinery_cost'] ?? 0),
            'overhead_cost' => 0,
            'profit_cost' => 0,
            'total_cost' => (float) ($workItem['total_cost'] ?? 0),
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
    private function unitPrice(array $workItem): float
    {
        $quantity = (float) ($workItem['quantity'] ?? 0);

        if ($quantity <= 0) {
            return 0.0;
        }

        return round((float) ($workItem['total_cost'] ?? 0) / $quantity, 6);
    }
}
