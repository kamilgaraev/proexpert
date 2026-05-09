<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Facades\DB;

class EstimateGenerationPackagePersistenceService
{
    /**
     * @param array<string, mixed> $draft
     */
    public function syncFromDraft(EstimateGenerationSession $session, array $draft): void
    {
        DB::transaction(function () use ($session, $draft): void {
            foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
                $workItems = $this->workItems($localEstimate);
                $quality = $this->packageQuality($localEstimate, $workItems);
                $itemCounters = $this->itemCounters($workItems);
                $package = EstimateGenerationPackage::query()->updateOrCreate(
                    [
                        'session_id' => $session->id,
                        'key' => (string) ($localEstimate['key'] ?? 'package-' . ($localIndex + 1)),
                    ],
                    [
                        'title' => (string) ($localEstimate['title'] ?? 'Локальная смета'),
                        'scope_type' => (string) ($localEstimate['scope_type'] ?? 'custom'),
                        'status' => $quality['level'] === 'blocked' ? 'blocked' : 'ready_for_review',
                        'generation_stage' => 'quality_check',
                        'generation_progress' => 100,
                        'target_items_min' => (int) ($localEstimate['target_items_min'] ?? 0),
                        'target_items_max' => (int) ($localEstimate['target_items_max'] ?? 0),
                        'actual_items_count' => $itemCounters['total_items_count'],
                        'totals' => [
                            'total_cost' => (float) ($localEstimate['totals']['total_cost'] ?? 0),
                            ...$itemCounters,
                        ],
                        'quality_summary' => $quality,
                        'assumptions' => $localEstimate['assumptions'] ?? [],
                        'source_refs' => $localEstimate['source_refs'] ?? [],
                        'metadata' => [
                            'generated_from' => 'estimate_generation_v2',
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
        });
    }

    /**
     * @param array<string, mixed> $localEstimate
     * @return array<int, array<string, mixed>>
     */
    private function workItems(array $localEstimate): array
    {
        $items = [];

        foreach ($localEstimate['sections'] ?? [] as $section) {
            foreach ($section['work_items'] ?? [] as $workItem) {
                $items[] = $workItem;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $localEstimate
     * @param array<int, array<string, mixed>> $workItems
     * @return array<string, mixed>
     */
    private function packageQuality(array $localEstimate, array $workItems): array
    {
        $critical = [];
        $warnings = [];

        if (count($workItems) < (int) ($localEstimate['target_items_min'] ?? 0)) {
            $critical[] = 'insufficient_detail';
        }

        foreach ($workItems as $workItem) {
            foreach ($workItem['validation_flags'] ?? [] as $flag) {
                if (in_array($flag, ['missing_price', 'missing_resources'], true)) {
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
     * @param array<int, array<string, mixed>> $workItems
     * @return array<string, int>
     */
    private function itemCounters(array $workItems): array
    {
        $priced = 0;
        $operations = 0;
        $reviewNotes = 0;

        foreach ($workItems as $workItem) {
            $type = (string) ($workItem['item_type'] ?? 'priced_work');

            if (in_array($type, ['operation', 'resource_note'], true)) {
                $operations++;
                continue;
            }

            if ($type === 'review_note') {
                $reviewNotes++;
                continue;
            }

            $priced++;
        }

        return [
            'items_count' => count($workItems),
            'total_items_count' => count($workItems),
            'priced_items_count' => $priced,
            'operation_items_count' => $operations,
            'review_notes_count' => $reviewNotes,
        ];
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function itemPayload(EstimateGenerationPackage $package, array $workItem, int $index): array
    {
        return [
            'package_id' => $package->id,
            'key' => (string) ($workItem['key'] ?? $package->key . '.item.' . ($index + 1)),
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
                'source_refs' => $workItem['source_refs'] ?? [],
                'confidence' => $workItem['confidence'] ?? null,
                ...($workItem['metadata'] ?? []),
            ],
            'sort_order' => ($index + 1) * 100,
        ];
    }

    /**
     * @param array<string, mixed> $workItem
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
