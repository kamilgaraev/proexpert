<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Integration;

use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EstimateCoverageService
{
    public function __construct(
        private readonly ContractEstimateService $contractEstimateService
    ) {}

    public function createFromContract(Contract $contract, array $additionalData = []): Estimate
    {
        return DB::transaction(function () use ($contract, $additionalData) {
            $estimateData = array_merge([
                'organization_id' => $contract->organization_id,
                'project_id' => $contract->project_id,
                'name' => 'Смета по договору ' . $contract->number,
                'type' => 'contractual',
                'estimate_date' => now(),
            ], $additionalData);

            return app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateService::class)
                ->create($estimateData);
        });
    }

    public function attachFullCoverage(Contract $contract, Estimate $estimate): Collection
    {
        $itemIds = $this->getWorkItemIds($estimate)->all();

        return $this->contractEstimateService->syncItems($contract, $estimate, $itemIds);
    }

    public function syncCoverageItems(Contract $contract, Estimate $estimate, array $itemIds): Collection
    {
        $this->assertOwnership($contract, $estimate);

        return $this->contractEstimateService->syncItems($contract, $estimate, $itemIds);
    }

    public function detachCoverage(Contract $contract, Estimate $estimate): void
    {
        ContractEstimateItem::query()
            ->where('contract_id', $contract->id)
            ->where('estimate_id', $estimate->id)
            ->delete();
    }

    public function getCoverageForEstimate(Estimate $estimate): array
    {
        $totalWorkItems = $this->getWorkItemIds($estimate)->count();

        $links = ContractEstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->with('contract.contractor')
            ->get()
            ->groupBy('contract_id');

        $contracts = $links->map(function (Collection $group) use ($totalWorkItems) {
            $contract = $group->first()?->contract;
            $linkedItemIds = $group->pluck('estimate_item_id')->unique()->values();
            $linkedItemsCount = $linkedItemIds->count();
            $coverageStatus = $this->resolveCoverageStatus($linkedItemsCount, $totalWorkItems);

            return [
                'contract_id' => $contract?->id,
                'contract' => $contract ? [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'date' => $contract->date?->format('Y-m-d'),
                    'total_amount' => (float) $contract->total_amount,
                    'contractor' => $contract->contractor ? [
                        'id' => $contract->contractor->id,
                        'name' => $contract->contractor->name,
                    ] : null,
                ] : null,
                'coverage_status' => $coverageStatus,
                'linked_items_count' => $linkedItemsCount,
                'available_items_count' => max(0, $totalWorkItems - $linkedItemsCount),
                'linked_amount' => round((float) $group->sum('amount'), 2),
                'is_full' => $coverageStatus === 'full_link',
            ];
        })->values();

        return [
            'estimate_id' => $estimate->id,
            'total_work_items' => $totalWorkItems,
            'contracts' => $contracts,
            'primary_contract' => $contracts->count() === 1 ? $contracts->first() : null,
            'coverage_status' => $this->resolveAggregateCoverageStatus($contracts),
            'legacy_contract_id' => $estimate->contract_id,
        ];
    }

    public function getContractCoverageSummary(Contract $contract): array
    {
        $links = ContractEstimateItem::query()
            ->where('contract_id', $contract->id)
            ->whereHas('estimateItem', fn ($query) => $query->works())
            ->with(['estimate', 'estimateItem'])
            ->get()
            ->groupBy('estimate_id');

        $contractAmount = round((float) ($contract->total_amount ?? 0), 2);

        $linkedEstimates = $links->map(function (Collection $group, int $estimateId) {
            $estimate = $group->first()?->estimate;
            $totalWorkItems = $estimate ? $this->getWorkItemIds($estimate)->count() : 0;
            $linkedItemsCount = $group->pluck('estimate_item_id')->unique()->count();
            $linkedAmount = round((float) $group->sum('amount'), 2);
            $linkedQuantities = $group->sum('quantity');
            $averageAmount = $linkedItemsCount > 0
                ? round($linkedAmount / $linkedItemsCount, 2)
                : 0.0;
            $maxAmount = round((float) $group->max('amount'), 2);
            $linkedSectionsCount = $group
                ->pluck('estimateItem.estimate_section_id')
                ->filter()
                ->unique()
                ->count();
            $coveragePercent = $totalWorkItems > 0
                ? round(($linkedItemsCount / $totalWorkItems) * 100, 2)
                : 0.0;

            return [
                'estimate_id' => $estimateId,
                'estimate' => $estimate ? [
                    'id' => $estimate->id,
                    'number' => $estimate->number,
                    'name' => $estimate->name,
                    'status' => $estimate->status,
                    'type' => $estimate->type,
                    'created_at' => $estimate->created_at?->toISOString(),
                    'total_amount' => (float) $estimate->total_amount,
                    'total_amount_with_vat' => (float) $estimate->total_amount_with_vat,
                ] : null,
                'coverage_status' => $this->resolveCoverageStatus($linkedItemsCount, $totalWorkItems),
                'linked_items_count' => $linkedItemsCount,
                'total_work_items' => $totalWorkItems,
                'unlinked_items_count' => max(0, $totalWorkItems - $linkedItemsCount),
                'coverage_percent' => $coveragePercent,
                'linked_items_summary' => [
                    'amount' => $linkedAmount,
                    'average_amount' => $averageAmount,
                    'max_amount' => $maxAmount,
                    'total_quantity' => round((float) $linkedQuantities, 2),
                    'sections_count' => $linkedSectionsCount,
                ],
            ];
        })->values();

        $linkedAmount = round((float) $linkedEstimates->sum('linked_items_summary.amount'), 2);
        $linkedItemsCount = (int) $linkedEstimates->sum('linked_items_count');
        $coveragePercent = $contractAmount > 0
            ? round(($linkedAmount / $contractAmount) * 100, 2)
            : 0.0;
        $uncoveredAmount = max(0.0, round($contractAmount - $linkedAmount, 2));
        $overcoveredAmount = max(0.0, round($linkedAmount - $contractAmount, 2));
        $averageLinkedItemAmount = $linkedItemsCount > 0
            ? round($linkedAmount / $linkedItemsCount, 2)
            : 0.0;

        return [
            'contract_id' => $contract->id,
            'linked_estimates' => $linkedEstimates,
            'summary' => [
                'estimates_count' => $linkedEstimates->count(),
                'linked_items_count' => $linkedItemsCount,
                'linked_amount' => $linkedAmount,
                'contract_amount' => $contractAmount,
                'coverage_percent' => $coveragePercent,
                'uncovered_amount' => $uncoveredAmount,
                'overcovered_amount' => $overcoveredAmount,
                'average_linked_item_amount' => $averageLinkedItemAmount,
            ],
        ];
    }

    public function getEstimatesByContract(Contract $contract): Collection
    {
        return collect($this->getContractCoverageSummary($contract)['linked_estimates']);
    }

    public function validateContractAmount(Estimate $estimate, ?int $contractId = null): array
    {
        $coverage = $this->getCoverageForEstimate($estimate);
        $contracts = collect($coverage['contracts']);

        if ($contractId !== null) {
            $contracts = $contracts->where('contract_id', $contractId)->values();
        }

        if ($contracts->isEmpty()) {
            return [
                'valid' => true,
                'estimate_amount' => (float) $estimate->total_amount,
                'covered_amount' => 0.0,
                'contract_amount' => null,
                'difference' => 0.0,
                'percentage_difference' => 0.0,
                'message' => 'Смета не покрыта договором',
                'coverage_status' => 'not_linked',
            ];
        }

        $primaryCoverage = $contracts->first();
        $contractAmount = (float) ($primaryCoverage['contract']['total_amount'] ?? 0);
        $coveredAmount = (float) ($primaryCoverage['linked_amount'] ?? 0);
        $difference = $coveredAmount - $contractAmount;
        $percentageDifference = $contractAmount > 0
            ? round(($difference / $contractAmount) * 100, 2)
            : 0.0;

        return [
            'valid' => abs($percentageDifference) <= 5,
            'estimate_amount' => (float) $estimate->total_amount,
            'covered_amount' => $coveredAmount,
            'contract_amount' => $contractAmount,
            'difference' => $difference,
            'percentage_difference' => $percentageDifference,
            'message' => abs($percentageDifference) <= 5
                ? 'Сумма покрытия сметы соответствует сумме договора'
                : 'Сумма покрытия сметы отличается от суммы договора более чем на 5%',
            'coverage_status' => $primaryCoverage['coverage_status'],
        ];
    }

    public function syncContractAmount(Estimate $estimate): void
    {
        $coverage = $this->getCoverageForEstimate($estimate);
        $contracts = collect($coverage['contracts']);

        if ($contracts->isEmpty()) {
            return;
        }

        $contracts->each(function (array $coverageItem) {
            $contract = Contract::find($coverageItem['contract_id']);
            if (!$contract || $contract->is_fixed_amount) {
                return;
            }

            $contract->update([
                'total_amount' => $coverageItem['linked_amount'],
            ]);
        });
    }

    public function backfillLegacyCoverage(): void
    {
        Estimate::query()
            ->whereNotNull('contract_id')
            ->with('items')
            ->chunkById(100, function (EloquentCollection $estimates): void {
                foreach ($estimates as $estimate) {
                    $contract = Contract::query()->find($estimate->contract_id);
                    if (!$contract) {
                        continue;
                    }

                    $this->attachFullCoverage($contract, $estimate);
                }
            });
    }

    private function getWorkItemIds(Estimate $estimate): Collection
    {
        return EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->works()
            ->pluck('id');
    }

    private function resolveCoverageStatus(int $linkedItemsCount, int $totalWorkItems): string
    {
        if ($linkedItemsCount === 0) {
            return 'not_linked';
        }

        if ($totalWorkItems > 0 && $linkedItemsCount >= $totalWorkItems) {
            return 'full_link';
        }

        return 'partial_link';
    }

    private function resolveAggregateCoverageStatus(Collection $contracts): string
    {
        if ($contracts->isEmpty()) {
            return 'not_linked';
        }

        if ($contracts->contains(fn (array $contract) => $contract['coverage_status'] === 'full_link')) {
            return 'full_link';
        }

        return 'partial_link';
    }

    private function assertOwnership(Contract $contract, Estimate $estimate): void
    {
        if (
            $contract->organization_id !== $estimate->organization_id
            || $contract->project_id !== $estimate->project_id
        ) {
            throw new \DomainException('Договор и смета должны принадлежать одной организации и одному проекту');
        }
    }
}
