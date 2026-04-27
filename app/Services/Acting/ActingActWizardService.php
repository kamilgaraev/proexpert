<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function trans_message;

class ActingActWizardService
{
    public function __construct(
        private readonly ActingPolicyResolver $policyResolver
    ) {
    }

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

        if (!$contract) {
            throw new BusinessLogicException(trans_message('act_reports.contract_not_found'), 404);
        }

        $policy = $this->policyResolver->resolveForContract($contract);
        $manualLines = $data['manual_lines'] ?? [];
        $selectedWorks = $data['selected_works'] ?? [];

        if (empty($selectedWorks) && empty($manualLines)) {
            throw new BusinessLogicException(trans_message('act_reports.empty_act_not_allowed'), 422);
        }

        if ($manualLines !== [] && !$canManageManualLines) {
            throw new BusinessLogicException(trans_message('act_reports.manual_line_permission_denied'), 403);
        }

        return DB::transaction(function () use ($contract, $data, $userId, $policy, $manualLines): ContractPerformanceAct {
            $act = ContractPerformanceAct::create([
                'contract_id' => $contract->id,
                'project_id' => $contract->project_id,
                'act_document_number' => $data['act_document_number'],
                'act_date' => $data['act_date'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'description' => $data['description'] ?? null,
                'amount' => 0,
                'status' => ContractPerformanceAct::STATUS_DRAFT,
                'is_approved' => false,
                'created_by_user_id' => $userId,
            ]);

            $this->createCompletedWorkLines($act, $contract, $data);
            $this->createManualLines($act, $manualLines, $policy, $userId);
            $act->recalculateAmount();

            return $act->fresh(['contract.project', 'contract.contractor', 'lines.completedWork']);
        });
    }

    private function createCompletedWorkLines(ContractPerformanceAct $act, Contract $contract, array $data): void
    {
        $selectedGroups = collect($data['selected_works'] ?? [])
            ->groupBy(fn (array $selectedWork): int => (int) $selectedWork['completed_work_id']);

        if ($selectedGroups->isEmpty()) {
            return;
        }

        $workIds = $selectedGroups->keys()->map(fn ($id): int => (int) $id)->values();

        $works = CompletedWork::query()
            ->with('estimateItem.contractLinks')
            ->whereIn('id', $workIds)
            ->where('contract_id', $contract->id)
            ->where('status', 'confirmed')
            ->where('work_origin_type', CompletedWork::ORIGIN_JOURNAL)
            ->whereNotNull('journal_entry_id')
            ->whereBetween('completion_date', [$data['period_start'], $data['period_end']])
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $actedQuantities = PerformanceActLine::query()
            ->whereIn('completed_work_id', $workIds)
            ->lockForUpdate()
            ->get()
            ->groupBy('completed_work_id')
            ->map(fn (Collection $lines): float => (float) $lines->sum('quantity'));

        foreach ($selectedGroups as $workId => $selectedWorks) {
            $workId = (int) $workId;
            $work = $works->get($workId);

            if (!$work) {
                throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
            }

            $effectiveQuantity = (float) ($work->completed_quantity ?? $work->quantity);
            $availableQuantity = round(max(0, $effectiveQuantity - (float) ($actedQuantities[$workId] ?? 0)), 4);
            $unitPrice = $this->resolveUnitPrice($work, $effectiveQuantity);
            $quantity = $this->sumRequestedQuantity($selectedWorks, $availableQuantity);

            if ($quantity <= 0 || $quantity > $availableQuantity) {
                throw new BusinessLogicException(trans_message('act_reports.invalid_acting_quantity'), 422);
            }

            PerformanceActLine::create([
                'performance_act_id' => $act->id,
                'completed_work_id' => $work->id,
                'estimate_item_id' => $work->estimate_item_id,
                'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
                'title' => trans_message('act_reports.completed_work_line_title', ['id' => (string) $work->id]),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => round($quantity * $unitPrice, 2),
            ]);

            $act->completedWorks()->syncWithoutDetaching([
                $work->id => [
                    'included_quantity' => $quantity,
                    'included_amount' => round($quantity * $unitPrice, 2),
                    'notes' => null,
                ],
            ]);
        }
    }

    private function sumRequestedQuantity(Collection $selectedWorks, float $availableQuantity): float
    {
        return round((float) $selectedWorks->sum(
            fn (array $selectedWork): float => (
                array_key_exists('quantity', $selectedWork) && $selectedWork['quantity'] !== null
            )
                ? (float) $selectedWork['quantity']
                : $availableQuantity
        ), 4);
    }

    private function resolveUnitPrice(CompletedWork $work, float $effectiveQuantity): float
    {
        if ($work->price !== null) {
            return round((float) $work->price, 2);
        }

        $contractLink = $work->estimateItem?->contractLinks
            ?->where('contract_id', $work->contract_id)
            ->sortBy('id')
            ->first();

        if ($contractLink && (float) $contractLink->quantity > 0) {
            return round((float) $contractLink->amount / (float) $contractLink->quantity, 2);
        }

        $estimateItem = $work->estimateItem;
        $estimatePrice = (float) (
            $estimateItem?->actual_unit_price
            ?? $estimateItem?->current_unit_price
            ?? $estimateItem?->unit_price
            ?? 0
        );

        if ($estimatePrice > 0) {
            return round($estimatePrice, 2);
        }

        $estimateQuantity = (float) ($estimateItem?->quantity_total ?? $estimateItem?->quantity ?? 0);
        $estimateAmount = (float) ($estimateItem?->current_total_amount ?? $estimateItem?->total_amount ?? 0);
        if ($estimateQuantity > 0 && $estimateAmount > 0) {
            return round($estimateAmount / $estimateQuantity, 2);
        }

        if ($effectiveQuantity <= 0) {
            return 0.0;
        }

        return round((float) ($work->total_amount ?? 0) / $effectiveQuantity, 2);
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
