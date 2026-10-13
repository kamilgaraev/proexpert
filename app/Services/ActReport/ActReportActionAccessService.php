<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Exceptions\BusinessLogicException;
use App\Models\ContractPerformanceAct;
use App\Models\User;
use App\Services\Contract\ContractAccessService;
use App\Services\Project\UserProjectAccessService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

final class ActReportActionAccessService
{
    public const PERMISSION_FIELD_CONFIRM = 'act_reports.field_confirm';

    public function __construct(
        private readonly ActReportAccessService $access,
        private readonly ContractAccessService $contractAccessService,
        private readonly UserProjectAccessService $projects,
    ) {}

    public function availableActions(Request $request, ContractPerformanceAct $act): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return [];
        }

        try {
            $organizationId = $this->access->currentOrganizationId($request);
            $this->access->authorizeAct($request, $act);
        } catch (BusinessLogicException) {
            return [];
        }

        $allowed = fn (string $permission): bool => $user->can($permission, ['organization_id' => $organizationId]);
        $actions = [];
        $mutable = ! $act->isLocked() && ! $act->is_approved;
        if ($allowed(ActReportAccessService::PERMISSION_VIEW)) {
            $actions = ['view', 'view_files', 'download_files'];
        }
        if ($allowed(ActReportAccessService::PERMISSION_EDIT)) {
            if ($mutable) {
                $actions[] = 'edit';
                if ($act->status === ContractPerformanceAct::STATUS_DRAFT) {
                    $actions[] = 'submit';
                }
            }
            if (in_array($act->status, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], true)) {
                $actions[] = 'upload_signed';
            }
        }
        if ($allowed(ActReportAccessService::PERMISSION_APPROVE)) {
            if ($act->status === ContractPerformanceAct::STATUS_PENDING_APPROVAL && $mutable) {
                $actions[] = 'approve';
                $actions[] = 'reject';
            }
            if (in_array($act->status, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], true)
                && $this->canAnnul($act)) {
                $actions[] = 'annul';
            }
        }
        foreach (['export_pdf' => ActReportAccessService::PERMISSION_EXPORT_PDF, 'export_excel' => ActReportAccessService::PERMISSION_EXPORT_EXCEL, 'download_pdf' => ActReportAccessService::PERMISSION_DOWNLOAD_PDF] as $action => $permission) {
            if ($allowed($permission)) {
                $official = (bool) $act->is_approved && in_array($act->status, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], true);
                if ($action === 'download_pdf' || $official) {
                    $actions[] = $action;
                } elseif ($action === 'export_pdf' && in_array($act->status, [ContractPerformanceAct::STATUS_DRAFT, ContractPerformanceAct::STATUS_PENDING_APPROVAL, ContractPerformanceAct::STATUS_REJECTED], true)) {
                    $actions[] = 'preview_pdf';
                }
            }
        }

        return $actions;
    }

    public function canAccessRecord(User $user, ContractPerformanceAct $act, int $organizationId, string $permission, bool $requireParty = false): bool
    {
        if (! $this->isActiveActor($user, $organizationId)) {
            return false;
        }

        $act->loadMissing('contract.contractor');
        $contract = $act->contract;
        $projectId = $act->project_id === null ? $contract?->project_id : $act->project_id;
        if (! $contract || ! $this->contractAccessService->canAccess($contract, $organizationId, $projectId === null ? null : (int) $projectId)) {
            return false;
        }
        if ($requireParty && (int) $contract->organization_id !== $organizationId
            && (int) ($contract->contractor?->source_organization_id ?? 0) !== $organizationId
            && ! $contract->parties()->where('linked_organization_id', $organizationId)->exists()) {
            return false;
        }
        if ($projectId === null) {
            return (! $requireParty || (int) $contract->organization_id === $organizationId)
                && $user->can($permission, ['organization_id' => $organizationId]);
        }
        if (! $this->projects->queryAccessibleProjects($user, $organizationId)->whereKey((int) $projectId)->exists()) {
            return false;
        }

        return $user->can($permission, ['organization_id' => $organizationId, 'project_id' => (int) $projectId, 'strict_project_scope' => true])
            || $user->can($permission, ['organization_id' => $organizationId]);
    }

    public function isActiveActor(User $user, int $organizationId): bool
    {
        return $organizationId > 0 && $user->organizations()->whereKey($organizationId)
            ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists();
    }

    private function canAnnul(ContractPerformanceAct $act): bool
    {
        $invoices = PaymentDocument::query()
            ->where('organization_id', (int) $act->contract?->organization_id)
            ->where(function ($query) use ($act): void {
                $query->where('origin_key', 'like', 'performance-act:'.$act->id.':%')
                    ->orWhere(function ($morphQuery) use ($act): void {
                        $morphQuery->whereIn('invoiceable_type', array_values(array_unique([
                            ContractPerformanceAct::class, $act->getMorphClass(),
                        ])))->where('invoiceable_id', $act->id);
                    });
            })->with('transactions')->get();
        foreach ($invoices as $invoice) {
            if (BigDecimal::of((string) $invoice->paid_amount)->isPositive()
                || in_array($invoice->status, [PaymentDocumentStatus::PAID, PaymentDocumentStatus::PARTIALLY_PAID], true)) {
                return false;
            }
            $netPayments = [];
            foreach ($invoice->transactions as $transaction) {
                if (in_array($transaction->status, [PaymentTransactionStatus::PENDING, PaymentTransactionStatus::PROCESSING], true)) {
                    return false;
                }
                if (in_array($transaction->status, [PaymentTransactionStatus::COMPLETED, PaymentTransactionStatus::REFUNDED], true)) {
                    $currency = (string) $transaction->currency;
                    $netPayments[$currency] = ($netPayments[$currency] ?? BigDecimal::zero())->plus((string) $transaction->amount);
                }
            }
            foreach ($netPayments as $netPayment) {
                if ($netPayment->isPositive()) {
                    return false;
                }
            }
        }

        return true;
    }
}
