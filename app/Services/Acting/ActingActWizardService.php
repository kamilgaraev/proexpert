<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Enums\Contract\ContractStatusEnum;
use App\Enums\CurrencyCode;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionQuantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function trans_message;

class ActingActWizardService
{
    public function __construct(
        private readonly ActingPolicyResolver $policyResolver,
        private readonly ActingQuantityReservationService $quantityReservations,
        private readonly PerformanceActFinancialBasisService $financialBasis,
        private readonly ManualActLineBasisService $manualLineBasis,
        private readonly PerformanceActFinancialTotalsService $financialTotals,
        private readonly FixedContractActAmountGuard $contractAmountGuard,
    ) {}

    public function createFromWizard(
        int $organizationId,
        array $data,
        ?int $userId,
        bool $canManageManualLines
    ): ContractPerformanceAct {
        $contract = Contract::query()
            ->where('id', $data['contract_id'])
            ->where('organization_id', $organizationId)
            ->first();

        if (! $contract) {
            throw new BusinessLogicException(trans_message('act_reports.contract_not_found'), 404);
        }
        if ($contract->status !== ContractStatusEnum::ACTIVE) {
            throw new BusinessLogicException(trans_message('act_reports.contract_not_active'), 422);
        }

        $policy = $this->policyResolver->resolveForContract($contract);
        $currency = CurrencyCode::tryFrom(strtoupper(trim((string) $contract->currency)));
        if ($currency === null) {
            throw new BusinessLogicException(trans_message('act_reports.contract_currency_required'), 422);
        }
        $manualLines = $data['manual_lines'] ?? [];
        $selectedWorks = $data['selected_works'] ?? [];

        if (empty($selectedWorks) && empty($manualLines)) {
            throw new BusinessLogicException(trans_message('act_reports.empty_act_not_allowed'), 422);
        }

        if ($manualLines !== [] && ! $canManageManualLines) {
            throw new BusinessLogicException(trans_message('act_reports.manual_line_permission_denied'), 403);
        }

        return DB::transaction(function () use (
            $organizationId,
            $contract,
            $data,
            $userId,
            $policy,
            $manualLines,
            $currency,
        ): ContractPerformanceAct {
            $lockedContract = Contract::query()
                ->whereKey($contract->id)
                ->lockForUpdate()
                ->firstOrFail();
            $selectedGroups = collect($data['selected_works'] ?? [])
                ->groupBy(fn (array $selectedWork): int => (int) $selectedWork['completed_work_id']);
            $works = $this->lockCompletedWorks($organizationId, $lockedContract, $data, $selectedGroups);
            $projectId = $this->resolveActProjectId($lockedContract, $works);
            $act = ContractPerformanceAct::create([
                'contract_id' => $lockedContract->id,
                'project_id' => $projectId,
                'act_document_number' => $data['act_document_number'],
                'act_date' => $data['act_date'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'description' => $data['description'] ?? null,
                'amount' => 0,
                'currency' => $currency->value,
                'status' => ContractPerformanceAct::STATUS_DRAFT,
                'is_approved' => false,
                'created_by_user_id' => $userId,
            ]);

            $this->createCompletedWorkLines($act, $lockedContract, $selectedGroups, $works, $currency);
            $this->createManualLines(
                $act,
                $lockedContract,
                $organizationId,
                $projectId,
                $manualLines,
                $policy,
                $userId,
                $currency,
            );
            $act = $this->financialTotals->synchronize($act);
            $this->contractAmountGuard->assertActFits($lockedContract, (string) $act->amount, (int) $act->id);

            return $act->fresh([
                'project',
                'contract.project',
                'contract.contractor',
                'estimateVersion',
                'lines.completedWork',
                'lines.estimateVersion',
            ]);
        });
    }

    private function createCompletedWorkLines(
        ContractPerformanceAct $act,
        Contract $contract,
        Collection $selectedGroups,
        Collection $works,
        CurrencyCode $currency,
    ): void {
        if ($selectedGroups->isEmpty()) {
            return;
        }
        $availableQuantities = $this->quantityReservations->availableQuantities($works->values());

        foreach ($selectedGroups as $workId => $selectedWorks) {
            $workId = (int) $workId;
            $work = $works->get($workId);

            if (! $work) {
                throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
            }

            $this->ensureCompletedWorkContract($work, $contract);

            if ($work->planning_status === CompletedWork::PLANNING_REQUIRES_SCHEDULE) {
                throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
            }

            $effectiveQuantity = (float) ($work->completed_quantity ?? $work->quantity);
            $availableQuantity = $availableQuantities[$workId] ?? 0;
            $basis = $this->financialBasis->forCompletedWork($work, $contract, $effectiveQuantity);
            $quantity = $this->sumRequestedQuantity($selectedWorks, $availableQuantity);
            $this->quantityReservations->assertScaledAvailable([$workId => $quantity], $availableQuantities);
            $quantityDecimal = AcceptedProductionQuantity::decimal($quantity);
            $amount = (string) BigDecimal::of($quantityDecimal)
                ->multipliedBy(BigDecimal::of($basis['unit_price']))
                ->toScale(2, RoundingMode::HalfUp);

            PerformanceActLine::create([
                'performance_act_id' => $act->id,
                'completed_work_id' => $work->id,
                'estimate_item_id' => $work->estimate_item_id,
                'estimate_version_id' => $basis['estimate_version_id'],
                'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
                'title' => $this->resolveCompletedWorkTitle($work),
                'unit' => ($basis['snapshot']['basis_type'] ?? null) === 'estimate_version'
                    ? PerformanceActLine::unitFromSnapshot($basis['snapshot'])
                    : $work->workType?->measurementUnit?->short_name,
                'quantity' => $quantityDecimal,
                'unit_price' => $basis['unit_price'],
                'amount' => $amount,
                'currency' => $currency->value,
                'basis_snapshot' => $basis['snapshot'],
            ]);

            $act->completedWorks()->syncWithoutDetaching([
                $work->id => [
                    'included_quantity' => $quantityDecimal,
                    'included_amount' => $amount,
                    'currency' => $currency->value,
                    'notes' => null,
                ],
            ]);
        }
    }

    private function lockCompletedWorks(
        int $organizationId,
        Contract $contract,
        array $data,
        Collection $selectedGroups,
    ): Collection {
        if ($selectedGroups->isEmpty()) {
            return collect();
        }

        $workIds = $selectedGroups->keys()->map(fn ($id): int => (int) $id)->values();
        $works = CompletedWork::query()
            ->with(
                'estimateItem.contractLinks',
                'estimateItem.estimate.currentVersion',
                'workType.measurementUnit',
                'journalEntry',
            )
            ->where('organization_id', $organizationId)
            ->whereIn('id', $workIds)
            ->where(function ($query) use ($contract): void {
                $query
                    ->where('contract_id', $contract->id)
                    ->orWhere(function ($fallbackQuery) use ($contract): void {
                        $fallbackQuery
                            ->whereNull('contract_id')
                            ->whereHas('estimateItem.contractLinks', function ($contractLinkQuery) use ($contract): void {
                                $contractLinkQuery->where('contract_id', $contract->id);
                            });
                    });
            })
            ->where('status', 'confirmed')
            ->where(function ($query): void {
                $query
                    ->where('work_origin_type', CompletedWork::ORIGIN_JOURNAL)
                    ->orWhereNotNull('journal_entry_id');
            })
            ->whereBetween('completion_date', [$data['period_start'], $data['period_end']])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($works->count() !== $workIds->count()) {
            throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
        }

        return $works;
    }

    private function resolveActProjectId(Contract $contract, Collection $works): int
    {
        $allowedProjectIds = collect($contract->getProjectIds())
            ->map(static fn ($projectId): int => (int) $projectId)
            ->filter(static fn (int $projectId): bool => $projectId > 0)
            ->unique()
            ->values();
        $hasInvalidProject = $works->contains(
            static fn (CompletedWork $work): bool => (int) $work->project_id < 1,
        );
        $projectIds = $works
            ->pluck('project_id')
            ->map(static fn ($projectId): int => (int) $projectId)
            ->filter(static fn (int $projectId): bool => $projectId > 0)
            ->unique()
            ->values();
        if ($hasInvalidProject || $projectIds->count() > 1 || ($works->isNotEmpty() && $projectIds->count() !== 1)) {
            throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
        }

        if ($works->isEmpty()) {
            if ($allowedProjectIds->count() !== 1) {
                throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
            }

            return (int) $allowedProjectIds->first();
        }

        $projectId = (int) $projectIds->first();
        if (! $allowedProjectIds->contains($projectId)) {
            throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
        }

        return $projectId;
    }

    private function ensureCompletedWorkContract(CompletedWork $work, Contract $contract): void
    {
        if ($work->contract_id !== null) {
            return;
        }

        $hasContractCoverage = $work->estimateItem?->contractLinks
            ?->contains(fn ($link): bool => (int) $link->contract_id === (int) $contract->id) ?? false;

        if (! $hasContractCoverage) {
            return;
        }

        $work->forceFill([
            'contract_id' => $contract->id,
            'contractor_id' => $work->contractor_id ?? $contract->contractor_id,
        ])->save();
    }

    private function resolveCompletedWorkTitle(CompletedWork $work): string
    {
        foreach ([
            $work->estimateItem?->name,
            $work->workType?->name,
            $work->journalEntry?->work_description,
        ] as $title) {
            if (is_string($title) && trim($title) !== '') {
                return trim($title);
            }
        }

        return trans_message('act_reports.completed_work_line_title', ['id' => (string) $work->id]);
    }

    private function sumRequestedQuantity(Collection $selectedWorks, int $availableQuantity): int
    {
        $requested = 0;
        foreach ($selectedWorks as $selectedWork) {
            $requested += array_key_exists('quantity', $selectedWork) && $selectedWork['quantity'] !== null
                ? AcceptedProductionQuantity::scaled(
                    (string) $selectedWork['quantity'],
                    'acting_quantity_requested_invalid',
                )
                : $availableQuantity;
        }

        return $requested;
    }

    private function createManualLines(
        ContractPerformanceAct $act,
        Contract $contract,
        int $organizationId,
        int $projectId,
        array $manualLines,
        array $policy,
        ?int $userId,
        CurrencyCode $currency,
    ): void {
        foreach ($manualLines as $manualLine) {
            $basis = $this->manualLineBasis->resolve(
                $organizationId,
                $projectId,
                $contract,
                $manualLine,
            );

            $line = new PerformanceActLine([
                'performance_act_id' => $act->id,
                'variation_order_id' => (int) $manualLine['variation_order_id'],
                'line_type' => PerformanceActLine::TYPE_MANUAL,
                'title' => $manualLine['title'],
                'unit' => $manualLine['unit'] ?? null,
                'quantity' => (string) $manualLine['quantity'],
                'unit_price' => $basis['unit_price'],
                'amount' => $basis['amount'],
                'currency' => $currency->value,
                'manual_reason' => $manualLine['manual_reason'] ?? null,
                'basis_snapshot' => $basis['snapshot'],
                'created_by' => $userId,
            ]);

            $line->assertManualLineAllowed($policy);
            $line->save();
        }
    }
}
