<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\PerformanceActLine;
use App\Models\User;
use App\Services\Acting\ActingActWizardService;
use App\Services\Acting\ActingAvailabilityService;
use App\Services\Acting\ActingPolicyResolver;
use App\Services\Acting\ActingPriceService;
use App\Services\Acting\KS3SummaryService;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceEventRecorder;
use App\Services\Workflow\WorkflowGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

class ActReportWorkflowService
{
    public function __construct(
        private readonly ActReportNotificationService $notificationService,
        private readonly ActingPriceService $priceService,
        private readonly ActingPolicyResolver $actingPolicyResolver,
        private readonly ActingAvailabilityService $actingAvailabilityService,
        private readonly KS3SummaryService $ks3SummaryService,
        private readonly ActingActWizardService $actingActWizardService,
        private readonly ActReportAccessService $accessService,
        private readonly ProductionAcceptanceEventRecorder $acceptanceEvents,
    ) {
    }

    public function preview(int $organizationId, array $data, ?User $user): array
    {
        $contract = $this->accessService->findAccessibleContractOrFail($organizationId, (int) $data['contract_id']);
        $policy = $this->actingPolicyResolver->resolveForContract($contract);
        $policy['can_override'] = (bool) $user?->can(
            WorkflowGuardService::PERMISSION_OVERRIDE,
            ['organization_id' => $organizationId]
        );

        return [
            'policy' => $policy,
            'available_works' => $this->actingAvailabilityService->getAvailableWorks(
                $contract->id,
                $data['period_start'],
                $data['period_end']
            ),
            'blocked_works' => $this->actingAvailabilityService->getBlockedWorks(
                $contract->id,
                $data['period_start'],
                $data['period_end']
            ),
            'summary' => $this->ks3SummaryService->summarize(
                $contract->id,
                $data['period_start'],
                $data['period_end']
            ),
        ];
    }

    public function createFromWizard(
        int $organizationId,
        array $data,
        ?User $user,
        bool $canManageManualLines
    ): ContractPerformanceAct {
        return $this->actingActWizardService->createFromWizard(
            $organizationId,
            $data,
            $user?->id,
            $canManageManualLines
        );
    }

    public function show(ContractPerformanceAct $act): ContractPerformanceAct
    {
        $act = $this->recalculatePricedLines($act);

        $act->load([
            'contract.project',
            'contract.contractor',
            'contract.organization',
            'completedWorks.workType',
            'completedWorks.user',
            'lines',
            'files',
        ]);

        return $act;
    }

    public function getContracts(int $organizationId): array
    {
        return $this->accessService->accessibleContractsQuery($organizationId)
            ->with(['project', 'contractor'])
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Contract $contract): array => [
                'id' => $contract->id,
                'number' => $contract->number,
                'subject' => $contract->subject,
                'status' => $contract->status,
                'project' => $contract->project ? [
                    'id' => $contract->project->id,
                    'name' => $contract->project->name,
                ] : null,
                'contractor' => $contract->contractor ? [
                    'id' => $contract->contractor->id,
                    'name' => $contract->contractor->name,
                ] : null,
            ])
            ->values()
            ->all();
    }

    public function update(ContractPerformanceAct $act, array $data): ContractPerformanceAct
    {
        try {
            $updatedAct = DB::transaction(function () use ($act, $data): ContractPerformanceAct {
                $lockedAct = ContractPerformanceAct::query()
                    ->whereKey($act->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedAct->is_approved) {
                    throw new BusinessLogicException(trans_message('act_reports.act_already_approved'), 400);
                }
                $this->assertMutable($lockedAct);

                $lockedAct->update([
                    'act_document_number' => $data['act_document_number'] ?? $lockedAct->act_document_number,
                    'act_date' => $data['act_date'] ?? $lockedAct->act_date,
                    'description' => $data['description'] ?? $lockedAct->description,
                ]);

                return $lockedAct->fresh([
                    'contract.project',
                    'contract.contractor',
                    'completedWorks',
                ]);
            });

            Log::info('[ActReportWorkflowService] Act updated', [
                'act_id' => $act->id,
            ]);

            return $updatedAct;
        } catch (BusinessLogicException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[ActReportWorkflowService] Failed to update act', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
            ]);

            throw new BusinessLogicException(trans_message('act_reports.update_failed'), 500, $e);
        }
    }

    public function submit(ContractPerformanceAct $act, int $userId): ContractPerformanceAct
    {
        $updatedAct = DB::transaction(function () use ($act, $userId): ContractPerformanceAct {
            $lockedAct = ContractPerformanceAct::query()
                ->whereKey($act->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertMutable($lockedAct);

            $lockedAct->update([
                'status' => ContractPerformanceAct::STATUS_PENDING_APPROVAL,
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            return $lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
        });

        $this->notificationService->notifyStatusChanged($updatedAct, trans_message('act_reports.act_submitted'));

        return $updatedAct;
    }

    public function approve(ContractPerformanceAct $act, int $userId): ContractPerformanceAct
    {
        [$updatedAct, $changed] = DB::transaction(function () use ($act, $userId): array {
            $lockedAct = ContractPerformanceAct::query()
                ->whereKey($act->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAct->status === ContractPerformanceAct::STATUS_REJECTED) {
                throw new BusinessLogicException(trans_message('act_reports.act_rejected_cannot_approve'), 422);
            }
            if (in_array($lockedAct->status, [
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_SIGNED,
            ], true)) {
                return [$lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']), false];
            }
            $lockedAct = $this->recalculatePricedLines($lockedAct);
            $previousStatus = $this->acceptanceStatus($lockedAct);
            if ((float) $lockedAct->amount <= 0) {
                throw new BusinessLogicException(trans_message('act_reports.empty_act'), 422);
            }

            $occurredAt = CarbonImmutable::now();
            $lockedAct->update([
                'status' => ContractPerformanceAct::STATUS_APPROVED,
                'is_approved' => true,
                'approval_date' => $occurredAt->toDateString(),
                'approved_by_user_id' => $userId,
                'locked_by_user_id' => $userId,
                'locked_at' => $occurredAt,
            ]);

            $updatedAct = $lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
            $this->acceptanceEvents->recordTransitionIfApplicable(
                $updatedAct,
                $previousStatus,
                $this->acceptanceStatus($updatedAct),
                $occurredAt,
                $userId,
            );

            return [$updatedAct, true];
        });

        if ($changed) {
            $this->notificationService->notifyStatusChanged($updatedAct, trans_message('act_reports.act_approved'));
        }

        return $updatedAct;
    }

    public function recalculatePricedLines(ContractPerformanceAct $act): ContractPerformanceAct
    {
        return DB::transaction(function () use ($act): ContractPerformanceAct {
            $act->loadMissing([
                'contract.estimate',
                'lines.estimateItem.contractLinks',
                'lines.estimateItem.estimate',
                'completedWorks',
            ]);

            $act->lines->each(function (PerformanceActLine $line) use ($act): void {
                $unitPrice = $this->priceService->resolveLineUnitPrice($act, $line);

                if ($unitPrice <= 0) {
                    return;
                }

                $quantity = (float) $line->quantity;
                $amount = round($quantity * $unitPrice, 2);

                if ((float) $line->amount === $amount && (float) $line->unit_price === $unitPrice) {
                    return;
                }

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

    public function reject(ContractPerformanceAct $act, int $userId, string $reason): ContractPerformanceAct
    {
        [$updatedAct, $changed] = DB::transaction(function () use ($act, $reason, $userId): array {
            $lockedAct = ContractPerformanceAct::query()
                ->whereKey($act->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedAct->status === ContractPerformanceAct::STATUS_REJECTED) {
                return [$lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']), false];
            }
            $this->assertMutable($lockedAct);
            $previousStatus = $this->acceptanceStatus($lockedAct);
            $occurredAt = CarbonImmutable::now();

            $lockedAct->update([
                'status' => ContractPerformanceAct::STATUS_REJECTED,
                'is_approved' => false,
                'approval_date' => null,
                'rejected_by_user_id' => $userId,
                'rejected_at' => $occurredAt,
                'rejection_reason' => $reason,
            ]);

            $updatedAct = $lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
            $this->acceptanceEvents->recordTransitionIfApplicable(
                $updatedAct,
                $previousStatus,
                $this->acceptanceStatus($updatedAct),
                $occurredAt,
                $userId,
            );

            return [$updatedAct, true];
        });

        if ($changed) {
            $this->notificationService->notifyStatusChanged($updatedAct, trans_message('act_reports.act_rejected'));
        }

        return $updatedAct;
    }

    public function markSigned(ContractPerformanceAct $act, int $fileId, int $userId): ContractPerformanceAct
    {
        [$updatedAct, $changed] = DB::transaction(function () use ($act, $fileId, $userId): array {
            $lockedAct = ContractPerformanceAct::query()
                ->whereKey($act->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $signedFile = File::query()
                ->whereKey($fileId)
                ->where('organization_id', (int) $lockedAct->contract()->value('organization_id'))
                ->where('fileable_id', (int) $lockedAct->id)
                ->where('fileable_type', ContractPerformanceAct::class)
                ->where('category', 'signed_act')
                ->first();
            if ($signedFile === null) {
                throw new BusinessLogicException(trans_message('act_reports.signed_file_invalid'), 422);
            }
            if (! $lockedAct->is_approved) {
                throw new BusinessLogicException(
                    trans_message('act_reports.act_must_be_approved_before_signing'),
                    422,
                );
            }
            if ($lockedAct->status === ContractPerformanceAct::STATUS_SIGNED) {
                if ((int) $lockedAct->signed_file_id !== $fileId) {
                    throw new BusinessLogicException(
                        trans_message('act_reports.signed_file_already_registered'),
                        409,
                    );
                }

                return [$lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']), false];
            }

            $previousStatus = $this->acceptanceStatus($lockedAct);
            $occurredAt = CarbonImmutable::now();
            $lockedAct->update([
                'status' => ContractPerformanceAct::STATUS_SIGNED,
                'signed_file_id' => $fileId,
                'signed_by_user_id' => $userId,
                'signed_at' => $occurredAt,
                'locked_by_user_id' => $lockedAct->locked_by_user_id ?? $userId,
                'locked_at' => $lockedAct->locked_at ?? $occurredAt,
            ]);

            $updatedAct = $lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
            $this->acceptanceEvents->recordTransitionIfApplicable(
                $updatedAct,
                $previousStatus,
                $this->acceptanceStatus($updatedAct),
                $occurredAt,
                $userId,
            );

            return [$updatedAct, true];
        });

        if ($changed) {
            $this->notificationService->notifyStatusChanged(
                $updatedAct,
                trans_message('act_reports.signed_file_uploaded'),
            );
        }

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

    private function acceptanceStatus(ContractPerformanceAct $act): string
    {
        if ((bool) $act->is_approved || in_array($act->status, [
            ContractPerformanceAct::STATUS_APPROVED,
            ContractPerformanceAct::STATUS_SIGNED,
        ], true)) {
            return ContractPerformanceAct::STATUS_APPROVED;
        }

        return (string) $act->status;
    }
}
