<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Repositories\EstimateRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class EstimateVersionService
{
    public function __construct(
        protected EstimateRepository $repository,
        protected EstimateService $estimateService
    ) {}

    public function createVersion(Estimate $estimate, string $label, ?string $comment = null): Estimate
    {
        return DB::transaction(function () use ($estimate, $label, $comment) {
            $latestVersion = $this->repository->getLatestVersion($estimate);
            $newVersionNumber = $latestVersion ? $latestVersion->version + 1 : $estimate->version + 1;
            
            $metadata = $estimate->metadata ?? [];
            $metadata['version_label'] = $label;
            $metadata['version_comment'] = $comment;

            $overrides = [
                'version' => $newVersionNumber,
                'parent_estimate_id' => $estimate->id,
                'status' => 'draft',
                'approved_at' => null,
                'approved_by_user_id' => Auth::id(), // Автор слепка
                'metadata' => $metadata,
            ];
            
            // В duplicate мы сбросим totals и прочее если надо, но тут мы создаем SNAPSHOT, 
            // так что копируем как есть.
            $newVersion = $this->estimateService->duplicate($estimate, null, null);
            $newVersion->update($overrides);
            
            return $newVersion;
        });
    }

    public function compareVersions(Estimate $version1, Estimate $version2): array
    {
        $comparison = [
            'basic_info' => $this->compareBasicInfo($version1, $version2),
            'totals' => $this->compareTotals($version1, $version2),
            'items' => $this->compareItems($version1, $version2),
        ];
        
        return $comparison;
    }

    public function getVersionHistory(Estimate $estimate): array
    {
        $versions = $this->repository->getVersions($estimate);
        $estimate->load('approvedBy');
        
        $formatVersion = function ($v, $parentEstimateId) {
            return [
                'id' => $v->id,
                'estimate_id' => $parentEstimateId,
                'version_number' => $v->version,
                'status' => $v->status,
                'label' => $v->metadata['version_label'] ?? ($v->metadata['version_description'] ?? 'Снапшот'),
                'comment' => $v->metadata['version_comment'] ?? null,
                'total_amount' => (float) $v->total_amount,
                'total_amount_with_vat' => (float) $v->total_amount_with_vat,
                'total_direct_costs' => (float) $v->total_direct_costs,
                'created_at' => $v->created_at?->toISOString(),
                'created_by' => $v->approvedBy?->name ?? 'Система',
            ];
        };

        $history = $versions->map(fn($v) => $formatVersion($v, $estimate->id));
        
        // Добавляем текущую (оригинальную) смету в список версий
        $original = $formatVersion($estimate, $estimate->id);
        $original['label'] = 'Оригинал (v1)';
        
        $allVersions = $history->push($original)->sortByDesc('version_number')->values()->toArray();
        
        return $allVersions;
    }

    public function rollback(Estimate $version): Estimate
    {
        return DB::transaction(function () use ($version) {
            $parentEstimate = $version->parentEstimate;
            
            if (!$parentEstimate) {
                throw new \Exception('Это не версия сметы');
            }
            
            return $this->createVersion($version, 'Откат к версии ' . $version->version);
        });
    }

    protected function compareBasicInfo(Estimate $v1, Estimate $v2): array
    {
        return [
            'name' => [
                'v1' => $v1->name,
                'v2' => $v2->name,
                'changed' => $v1->name !== $v2->name,
            ],
            'vat_rate' => [
                'v1' => $v1->vat_rate,
                'v2' => $v2->vat_rate,
                'changed' => $v1->vat_rate != $v2->vat_rate,
            ],
            'overhead_rate' => [
                'v1' => $v1->overhead_rate,
                'v2' => $v2->overhead_rate,
                'changed' => $v1->overhead_rate != $v2->overhead_rate,
            ],
            'profit_rate' => [
                'v1' => $v1->profit_rate,
                'v2' => $v2->profit_rate,
                'changed' => $v1->profit_rate != $v2->profit_rate,
            ],
        ];
    }

    protected function compareTotals(Estimate $v1, Estimate $v2): array
    {
        return [
            'total_amount' => [
                'v1' => (float) $v1->total_amount,
                'v2' => (float) $v2->total_amount,
                'difference' => (float) ($v2->total_amount - $v1->total_amount),
                'percentage' => $v1->total_amount > 0 
                    ? round((($v2->total_amount - $v1->total_amount) / $v1->total_amount) * 100, 2) 
                    : 0,
            ],
            'items_count' => [
                'v1' => $v1->items()->count(),
                'v2' => $v2->items()->count(),
                'difference' => $v2->items()->count() - $v1->items()->count(),
            ],
        ];
    }

    protected function compareItems(Estimate $v1, Estimate $v2): array
    {
        $items1 = $v1->items()->get()->keyBy('position_number');
        $items2 = $v2->items()->get()->keyBy('position_number');
        
        $added = $items2->diffKeys($items1)->values();
        $removed = $items1->diffKeys($items2)->values();
        
        $changed = collect();
        foreach ($items1 as $key => $item1) {
            if ($items2->has($key)) {
                $item2 = $items2->get($key);
                if ($this->itemsAreDifferent($item1, $item2)) {
                    $changed->push([
                        'position_number' => $key,
                        'name' => $item1->name,
                        'v1' => [
                            'quantity' => (float) $item1->quantity,
                            'unit_price' => (float) $item1->unit_price,
                            'total_amount' => (float) $item1->total_amount,
                        ],
                        'v2' => [
                            'quantity' => (float) $item2->quantity,
                            'unit_price' => (float) $item2->unit_price,
                            'total_amount' => (float) $item2->total_amount,
                        ],
                    ]);
                }
            }
        }
        
        return [
            'added_count' => $added->count(),
            'removed_count' => $removed->count(),
            'changed_count' => $changed->count(),
            'added' => $added->map(fn($item) => [
                'position_number' => $item->position_number,
                'name' => $item->name,
                'total_amount' => (float) $item->total_amount,
            ]),
            'removed' => $removed->map(fn($item) => [
                'position_number' => $item->position_number,
                'name' => $item->name,
                'total_amount' => (float) $item->total_amount,
            ]),
            'changed' => $changed,
        ];
    }

    protected function itemsAreDifferent($item1, $item2): bool
    {
        return $item1->quantity != $item2->quantity 
            || $item1->unit_price != $item2->unit_price 
            || $item1->name !== $item2->name;
    }
}

