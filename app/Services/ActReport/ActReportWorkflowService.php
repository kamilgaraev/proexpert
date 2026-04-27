<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use Illuminate\Support\Facades\DB;

use function trans_message;

class ActReportWorkflowService
{
    public function __construct(
        private readonly ActReportNotificationService $notificationService
    ) {
    }

    public function submit(ContractPerformanceAct $act, int $userId): ContractPerformanceAct
    {
        $this->assertMutable($act);

        $updatedAct = DB::transaction(function () use ($act, $userId): ContractPerformanceAct {
            $act->update([
                'status' => ContractPerformanceAct::STATUS_PENDING_APPROVAL,
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            return $act->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
        });

        $this->notificationService->notifyStatusChanged($updatedAct, trans_message('act_reports.act_submitted'));

        return $updatedAct;
    }

    public function approve(ContractPerformanceAct $act, int $userId): ContractPerformanceAct
    {
        $act = $this->recalculatePricedLines($act);

        if ($act->status === ContractPerformanceAct::STATUS_REJECTED) {
            throw new BusinessLogicException(trans_message('act_reports.act_rejected_cannot_approve'), 422);
        }

        if ((float) $act->amount <= 0) {
            throw new BusinessLogicException(trans_message('act_reports.empty_act'), 422);
        }

        $updatedAct = DB::transaction(function () use ($act, $userId): ContractPerformanceAct {
            $act->update([
                'status' => ContractPerformanceAct::STATUS_APPROVED,
                'is_approved' => true,
                'approval_date' => now()->toDateString(),
                'approved_by_user_id' => $userId,
                'locked_by_user_id' => $userId,
                'locked_at' => now(),
            ]);

            return $act->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
        });

        $this->notificationService->notifyStatusChanged($updatedAct, trans_message('act_reports.act_approved'));

        return $updatedAct;
    }

    private function recalculatePricedLines(ContractPerformanceAct $act): ContractPerformanceAct
    {
        return DB::transaction(function () use ($act): ContractPerformanceAct {
            $act->loadMissing(['lines.estimateItem.contractLinks', 'completedWorks']);

            $act->lines->each(function (PerformanceActLine $line) use ($act): void {
                if ((float) $line->amount > 0) {
                    return;
                }

                $unitPrice = $this->resolveLineUnitPrice($act, $line);

                if ($unitPrice <= 0) {
                    return;
                }

                $quantity = (float) $line->quantity;
                $amount = round($quantity * $unitPrice, 2);
                $line->update([
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                ]);

                if ($line->completed_work_id) {
                    $act->completedWorks()->updateExistingPivot($line->completed_work_id, [
                        'included_amount' => $amount,
                    ]);
                }
            });

            $act->recalculateAmount();

            return $act->fresh(['contract.project', 'contract.contractor', 'lines.estimateItem', 'files']);
        });
    }

    private function resolveLineUnitPrice(ContractPerformanceAct $act, PerformanceActLine $line): float
    {
        $contractLink = $line->estimateItem?->contractLinks
            ?->where('contract_id', $act->contract_id)
            ->sortBy('id')
            ->first();

        if (!$contractLink && $line->estimate_item_id) {
            $contractLink = ContractEstimateItem::query()
                ->where('contract_id', $act->contract_id)
                ->where('estimate_item_id', $line->estimate_item_id)
                ->orderBy('id')
                ->first();
        }

        if ($contractLink && (float) $contractLink->quantity > 0) {
            return round((float) $contractLink->amount / (float) $contractLink->quantity, 2);
        }

        $estimateItem = $line->estimateItem;
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

        return 0.0;
    }

    public function reject(ContractPerformanceAct $act, int $userId, string $reason): ContractPerformanceAct
    {
        $this->assertMutable($act);

        $updatedAct = DB::transaction(function () use ($act, $userId, $reason): ContractPerformanceAct {
            $act->update([
                'status' => ContractPerformanceAct::STATUS_REJECTED,
                'is_approved' => false,
                'approval_date' => null,
                'rejected_by_user_id' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $act->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
        });

        $this->notificationService->notifyStatusChanged($updatedAct, trans_message('act_reports.act_rejected'));

        return $updatedAct;
    }

    public function markSigned(ContractPerformanceAct $act, int $fileId, int $userId): ContractPerformanceAct
    {
        if (!$act->is_approved) {
            throw new BusinessLogicException(trans_message('act_reports.act_must_be_approved_before_signing'), 422);
        }

        $updatedAct = DB::transaction(function () use ($act, $fileId, $userId): ContractPerformanceAct {
            $act->update([
                'status' => ContractPerformanceAct::STATUS_SIGNED,
                'signed_file_id' => $fileId,
                'signed_by_user_id' => $userId,
                'signed_at' => now(),
                'locked_by_user_id' => $act->locked_by_user_id ?? $userId,
                'locked_at' => $act->locked_at ?? now(),
            ]);

            return $act->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
        });

        $this->notificationService->notifyStatusChanged($updatedAct, trans_message('act_reports.signed_file_uploaded'));

        return $updatedAct;
    }

    public function financialSummary(ContractPerformanceAct $act): array
    {
        $contractId = (int) $act->contract_id;

        $contractDocuments = PaymentDocument::query()
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contractId);

        $actDocuments = PaymentDocument::query()
            ->where('invoiceable_type', ContractPerformanceAct::class)
            ->where('invoiceable_id', $act->id);

        $totalPaid = (float) (clone $contractDocuments)->sum('paid_amount')
            + (float) (clone $actDocuments)->sum('paid_amount');

        $totalRemaining = (float) (clone $contractDocuments)->sum('remaining_amount')
            + (float) (clone $actDocuments)->sum('remaining_amount');

        $acceptedAmount = (float) $act->amount;
        $debtAmount = $totalRemaining > 0 ? $totalRemaining : max(0.0, $acceptedAmount - $totalPaid);

        return [
            'accepted_amount' => round($acceptedAmount, 2),
            'paid_amount' => round(min($totalPaid, max($acceptedAmount, $totalPaid)), 2),
            'debt_amount' => round($debtAmount, 2),
            'payment_documents_count' => (clone $contractDocuments)->count() + (clone $actDocuments)->count(),
            'is_ready_for_payment' => $act->isReadyForPayment(),
        ];
    }

    public function assertMutable(ContractPerformanceAct $act): void
    {
        if ($act->isLocked()) {
            throw new BusinessLogicException(trans_message('act_reports.act_period_locked'), 423);
        }
    }
}
