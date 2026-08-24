<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\PerformanceActReversal;
use App\Models\User;
use App\Services\Acting\ActingActWizardService;
use App\Services\Acting\ActingAvailabilityService;
use App\Services\Acting\ActingPolicyResolver;
use App\Services\Acting\KS3SummaryService;
use App\Services\Acting\PerformanceActFinancialTotalsService;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceEventRecorder;
use App\Services\Workflow\WorkflowGuardService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

class ActReportWorkflowService
{
    public function __construct(
        private readonly ActReportNotificationService $notificationService,
        private readonly PerformanceActFinancialTotalsService $financialTotals,
        private readonly ActingPolicyResolver $actingPolicyResolver,
        private readonly ActingAvailabilityService $actingAvailabilityService,
        private readonly KS3SummaryService $ks3SummaryService,
        private readonly ActingActWizardService $actingActWizardService,
        private readonly ActReportAccessService $accessService,
        private readonly ProductionAcceptanceEventRecorder $acceptanceEvents,
        private readonly PaymentDocumentService $paymentDocumentService,
    ) {}

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
            'available_variation_orders' => $this->availableVariationOrders($contract),
            'summary' => $this->ks3SummaryService->summarize(
                $contract->id,
                $data['period_start'],
                $data['period_end']
            ),
        ];
    }

    /**
     * @return list<array{id: int, variation_number: string, change_number: string, description: ?string, amount: string, reserved_amount: string, remaining_amount: string}>
     */
    private function availableVariationOrders(Contract $contract): array
    {
        $reserved = DB::table('performance_act_lines as line')
            ->join('contract_performance_acts as act', 'act.id', '=', 'line.performance_act_id')
            ->whereNotNull('line.variation_order_id')
            ->whereNotIn('act.status', ['rejected', 'annulled', 'cancelled'])
            ->selectRaw('line.variation_order_id, COALESCE(SUM(line.amount), 0) AS reserved_amount')
            ->groupBy('line.variation_order_id');

        return DB::table('change_management_variation_orders as variation')
            ->join('change_management_change_requests as change', 'change.id', '=', 'variation.change_request_id')
            ->join('contract_project_allocations as allocation', function ($join): void {
                $join->on('allocation.id', '=', 'change.reporting_contract_project_allocation_id')
                    ->where('allocation.is_active', true)
                    ->whereNull('allocation.deleted_at');
            })
            ->leftJoinSub($reserved, 'reserved', function ($join): void {
                $join->on('reserved.variation_order_id', '=', 'variation.id');
            })
            ->where('variation.organization_id', $contract->organization_id)
            ->where('change.organization_id', $contract->organization_id)
            ->where('allocation.contract_id', $contract->id)
            ->where('allocation.project_id', $contract->project_id)
            ->whereIn('change.status', ['approved', 'implemented', 'closed'])
            ->orderBy('variation.id')
            ->get([
                'variation.id',
                'variation.variation_number',
                'variation.description',
                'variation.amount',
                'change.change_number',
                DB::raw('COALESCE(reserved.reserved_amount, 0) AS reserved_amount'),
            ])
            ->map(static function (object $row): array {
                $amount = BigDecimal::of((string) $row->amount)->toScale(2, RoundingMode::HalfUp);
                $reservedAmount = BigDecimal::of((string) $row->reserved_amount)->toScale(2, RoundingMode::HalfUp);
                $remainingAmount = $amount->minus($reservedAmount)->toScale(2, RoundingMode::HalfUp);

                return [
                    'id' => (int) $row->id,
                    'variation_number' => (string) $row->variation_number,
                    'change_number' => (string) $row->change_number,
                    'description' => $row->description === null ? null : (string) $row->description,
                    'amount' => (string) $amount,
                    'reserved_amount' => (string) $reservedAmount,
                    'remaining_amount' => (string) $remainingAmount,
                ];
            })
            ->filter(static fn (array $row): bool => BigDecimal::of($row['remaining_amount'])->isPositive())
            ->values()
            ->all();
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
        [$updatedAct, $changed] = DB::transaction(function () use ($act, $userId): array {
            $lockedAct = ContractPerformanceAct::query()
                ->whereKey($act->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedAct->status === ContractPerformanceAct::STATUS_PENDING_APPROVAL) {
                return [
                    $lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']),
                    false,
                ];
            }
            if ($lockedAct->status !== ContractPerformanceAct::STATUS_DRAFT) {
                throw new BusinessLogicException(
                    trans_message('act_reports.act_must_be_draft_before_submission'),
                    422,
                );
            }
            $this->assertMutable($lockedAct);

            $lockedAct->update([
                'status' => ContractPerformanceAct::STATUS_PENDING_APPROVAL,
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            return [
                $lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']),
                true,
            ];
        });

        if ($changed) {
            $this->notificationService->notifyStatusChanged($updatedAct, trans_message('act_reports.act_submitted'));
        }

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
            if ($lockedAct->status !== ContractPerformanceAct::STATUS_PENDING_APPROVAL) {
                throw new BusinessLogicException(trans_message('act_reports.act_must_be_submitted_before_approval'), 422);
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
        return DB::transaction(
            fn (): ContractPerformanceAct => $this->financialTotals->recalculateFromStoredBasis($act)
        );
    }

    public function reject(ContractPerformanceAct $act, int $userId, string $reason): ContractPerformanceAct
    {
        [$updatedAct, $changed] = DB::transaction(function () use ($act, $reason, $userId): array {
            $lockedAct = ContractPerformanceAct::query()
                ->whereKey($act->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedAct->status === ContractPerformanceAct::STATUS_REJECTED) {
                if ($lockedAct->rejection_reason !== $reason) {
                    throw new BusinessLogicException(
                        trans_message('act_reports.rejection_retry_conflict'),
                        409,
                    );
                }

                return [$lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']), false];
            }
            if ($lockedAct->status !== ContractPerformanceAct::STATUS_PENDING_APPROVAL) {
                throw new BusinessLogicException(
                    trans_message('act_reports.act_must_be_submitted_before_rejection'),
                    422,
                );
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

    public function annul(
        ContractPerformanceAct $act,
        int $userId,
        string $reason,
        string $idempotencyKey,
    ): ContractPerformanceAct {
        return DB::transaction(function () use ($act, $userId, $reason, $idempotencyKey): ContractPerformanceAct {
            $lockedAct = ContractPerformanceAct::query()
                ->whereKey($act->getKey())
                ->with('contract')
                ->lockForUpdate()
                ->firstOrFail();
            $organizationId = (int) $lockedAct->contract?->organization_id;
            $existing = PerformanceActReversal::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                if ((int) $existing->performance_act_id !== (int) $lockedAct->id
                    || $existing->reason !== $reason) {
                    throw new BusinessLogicException(trans_message('act_reports.annulment_idempotency_conflict'), 409);
                }

                return $lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
            }
            if ($lockedAct->status === ContractPerformanceAct::STATUS_ANNULLED) {
                throw new BusinessLogicException(trans_message('act_reports.act_already_annulled'), 409);
            }
            if (! in_array($lockedAct->status, [
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_SIGNED,
            ], true)) {
                throw new BusinessLogicException(trans_message('act_reports.only_accepted_act_can_be_annulled'), 422);
            }

            $invoices = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->where(function ($query) use ($lockedAct): void {
                    $query->where('origin_key', 'like', 'performance-act:'.$lockedAct->id.':%')
                        ->orWhere(function ($morphQuery) use ($lockedAct): void {
                            $morphQuery
                                ->whereIn('invoiceable_type', array_values(array_unique([
                                    ContractPerformanceAct::class,
                                    $lockedAct->getMorphClass(),
                                ])))
                                ->where('invoiceable_id', $lockedAct->id);
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($invoices as $invoice) {
                if (BigDecimal::of((string) $invoice->paid_amount)->isPositive()
                    || in_array($invoice->status, [
                        PaymentDocumentStatus::PAID,
                        PaymentDocumentStatus::PARTIALLY_PAID,
                    ], true)) {
                    throw new BusinessLogicException(trans_message('act_reports.paid_invoice_blocks_annulment'), 409);
                }
            }
            foreach ($invoices as $invoice) {
                if ($invoice->status !== PaymentDocumentStatus::CANCELLED) {
                    $this->paymentDocumentService->cancel($invoice, $reason, User::find($userId));
                }
            }

            $occurredAt = CarbonImmutable::now();
            $previousStatus = $this->acceptanceStatus($lockedAct);
            PerformanceActReversal::query()->create([
                'organization_id' => $organizationId,
                'performance_act_id' => $lockedAct->id,
                'reversed_by_user_id' => $userId,
                'source_status' => $lockedAct->status,
                'amount' => $lockedAct->amount,
                'currency' => $lockedAct->currency,
                'reason' => $reason,
                'invoice_ids' => $invoices->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                'idempotency_key' => $idempotencyKey,
                'reversed_at' => $occurredAt,
            ]);
            $lockedAct->forceFill([
                'status' => ContractPerformanceAct::STATUS_ANNULLED,
                'is_approved' => false,
                'annulled_at' => $occurredAt,
                'annulled_by_user_id' => $userId,
                'annulment_reason' => $reason,
            ])->save();
            $updatedAct = $lockedAct->fresh(['contract.project', 'contract.contractor', 'lines', 'files']);
            $this->acceptanceEvents->recordTransitionIfApplicable(
                $updatedAct,
                $previousStatus,
                $this->acceptanceStatus($updatedAct),
                $occurredAt,
                $userId,
            );

            return $updatedAct;
        });
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

        $totalPaid = BigDecimal::of((string) (clone $contractDocuments)->sum('paid_amount'))
            ->plus((string) (clone $actDocuments)->sum('paid_amount'))
            ->toScale(2, RoundingMode::HalfUp);
        $totalRemaining = BigDecimal::of((string) (clone $contractDocuments)->sum('remaining_amount'))
            ->plus((string) (clone $actDocuments)->sum('remaining_amount'))
            ->toScale(2, RoundingMode::HalfUp);
        $acceptedAmount = BigDecimal::of((string) $act->amount)->toScale(2, RoundingMode::HalfUp);
        $calculatedDebt = $acceptedAmount->minus($totalPaid);
        $debtAmount = $totalRemaining->isPositive()
            ? $totalRemaining
            : ($calculatedDebt->isPositive() ? $calculatedDebt : BigDecimal::zero()->toScale(2));

        return [
            'accepted_amount' => (string) $acceptedAmount,
            'paid_amount' => (string) $totalPaid,
            'debt_amount' => (string) $debtAmount,
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
