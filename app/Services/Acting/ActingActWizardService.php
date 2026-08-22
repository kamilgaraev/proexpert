<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Enums\CurrencyCode;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionQuantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function trans_message;

class ActingActWizardService
{
    public function __construct(
        private readonly ActingPolicyResolver $policyResolver,
        private readonly ActingPriceService $priceService,
        private readonly ActingQuantityReservationService $quantityReservations,
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
            $selectedGroups = collect($data['selected_works'] ?? [])
                ->groupBy(fn (array $selectedWork): int => (int) $selectedWork['completed_work_id']);
            $works = $this->lockCompletedWorks($organizationId, $contract, $data, $selectedGroups);
            $projectId = $this->resolveActProjectId($contract, $works);
            $act = ContractPerformanceAct::create([
                'contract_id' => $contract->id,
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

            $this->createCompletedWorkLines($act, $contract, $selectedGroups, $works, $currency);
            $this->createManualLines($act, $manualLines, $policy, $userId);
            $act->recalculateAmount();

            return $act->fresh(['project', 'contract.project', 'contract.contractor', 'lines.completedWork']);
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
            $unitPrice = $this->priceService->resolveCompletedWorkUnitPrice($work, $effectiveQuantity);
            $quantity = $this->sumRequestedQuantity($selectedWorks, $availableQuantity);
            $this->quantityReservations->assertAvailable([$workId => $quantity], $availableQuantities);
            $quantityDecimal = AcceptedProductionQuantity::decimal($quantity);

            PerformanceActLine::create([
                'performance_act_id' => $act->id,
                'completed_work_id' => $work->id,
                'estimate_item_id' => $work->estimate_item_id,
                'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
                'title' => $this->resolveCompletedWorkTitle($work),
                'quantity' => $quantityDecimal,
                'unit_price' => $unitPrice,
                'amount' => round((float) $quantityDecimal * $unitPrice, 2),
                'currency' => $currency->value,
            ]);

            $act->completedWorks()->syncWithoutDetaching([
                $work->id => [
                    'included_quantity' => $quantityDecimal,
                    'included_amount' => round((float) $quantityDecimal * $unitPrice, 2),
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
                'estimateItem.estimate',
                'workType',
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
        array $manualLines,
        array $policy,
        ?int $userId
    ): void {
        foreach ($manualLines as $manualLine) {
            $quantity = (float) $manualLine['quantity'];
            $unitPrice = isset($manualLine['unit_price']) ? (float) $manualLine['unit_price'] : null;
            $amount = isset($manualLine['amount'])
                ? (float) $manualLine['amount']
                : round($quantity * (float) ($unitPrice ?? 0), 2);

            $line = new PerformanceActLine([
                'performance_act_id' => $act->id,
                'line_type' => PerformanceActLine::TYPE_MANUAL,
                'title' => $manualLine['title'],
                'unit' => $manualLine['unit'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'manual_reason' => $manualLine['manual_reason'] ?? null,
                'created_by' => $userId,
            ]);

            $line->assertManualLineAllowed($policy);
            $line->save();
        }
    }
}
