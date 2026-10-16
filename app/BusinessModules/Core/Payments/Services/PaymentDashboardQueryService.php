<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Enums\Contract\ContractAllocationTypeEnum;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PaymentDashboardQueryService
{
    public function __construct(
        private readonly FinancialBalanceQuery $financialBalances,
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projects,
    ) {}

    public function getDashboard(User $actor, int $organizationId, int $days, ?int $projectId = null): array
    {
        $this->authorize($actor, $organizationId, $projectId);

        return [
            'summary' => $this->getSummary($organizationId, $projectId),
            'documents_by_status' => $this->getDocumentsByStatus($organizationId, $projectId),
            'documents_by_type' => $this->getDocumentsByType($organizationId, $projectId),
            'overdue_documents' => $this->getOverdueDocuments($organizationId, $projectId),
            'upcoming_documents' => $this->getUpcomingDocuments($organizationId, $projectId),
            'cash_flow' => $this->getCashFlow($organizationId, $days, $projectId),
            'top_debtors' => $this->getTopDebtors($organizationId, $projectId),
            'top_creditors' => $this->getTopCreditors($organizationId, $projectId),
            'by_projects' => $this->getByProjects($organizationId, $projectId),
            'payment_trends' => $this->getPaymentTrends($organizationId, $days, $projectId),
            'contract_comparison' => $this->getContractComparison($organizationId, $projectId),
        ];
    }

    private function authorize(User $actor, int $organizationId, ?int $projectId): void
    {
        if ($organizationId <= 0 || (int) $actor->current_organization_id !== $organizationId || !$actor->exists
            || !User::query()->useWritePdo()->whereKey($actor->id)->where('is_active', true)->exists()
            || !$actor->organizations()->useWritePdo()->where('organizations.id', $organizationId)
                ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists()) {
            throw new AuthorizationException;
        }

        if ($projectId !== null && ($projectId <= 0 || !$this->projects->queryAccessibleProjects($actor, $organizationId, true)
            ->useWritePdo()->where('projects.id', $projectId)->exists())) {
            throw new AuthorizationException;
        }

        if (!$this->authorization->canCurrent($actor, 'payments.dashboard.view', ['organization_id' => $organizationId])
            && ($projectId === null || !$this->authorization->canCurrent($actor, 'payments.dashboard.view', [
                'organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true,
            ]))) {
            throw new AuthorizationException;
        }
    }

    private function getSummary(int $organizationId, ?int $projectId): array
    {
        $receivableBalance = $this->financialBalances->forOrganization(
            $organizationId,
            InvoiceDirection::INCOMING->value,
            $projectId
        );
        $payableBalance = $this->financialBalances->forOrganization(
            $organizationId,
            InvoiceDirection::OUTGOING->value,
            $projectId
        );
        $totalBalance = $this->financialBalances->forOrganization($organizationId, null, $projectId);
        $totalReceivable = (float) $receivableBalance->debtAmount;
        $totalPayable = (float) $payableBalance->debtAmount;

        // Просроченные платежи
        $overdueAmount = $this->documents($organizationId, $projectId)
            ->where(function ($query) {
                $query->whereNotNull('overdue_since')
                    ->orWhere(function ($query) {
                        $query->where('due_date', '<', now())
                            ->whereIn('status', [PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED]);
                    });
            })
            ->sum('remaining_amount');

        // Предстоящие платежи (7 дней)
        $upcomingPayments7days = $this->documents($organizationId, $projectId)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->whereBetween('due_date', [now(), now()->addDays(7)])
            ->sum('remaining_amount');

        // Оплачено за текущий месяц
        $paidThisMonth = $this->documents($organizationId, $projectId)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('paid_amount');

        // Выставлено за текущий месяц
        $issuedThisMonth = $this->documents($organizationId, $projectId)
            ->whereNotNull('issued_at')
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        // Всего активных документов
        $activeInvoicesCount = $this->documents($organizationId, $projectId)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->count();

        // Всего оплаченных документов
        $paidInvoicesCount = $this->documents($organizationId, $projectId)
            ->where('status', PaymentDocumentStatus::PAID)
            ->count();

        return [
            // Задолженности
            'total_receivable' => (float) $totalReceivable,
            'total_payable' => (float) $totalPayable,
            'net_position' => (float) ($totalReceivable - $totalPayable),
            'overdue_amount' => (float) $overdueAmount,
            'upcoming_payments_7days' => (float) $upcomingPayments7days,

            // Месячная статистика
            'paid_this_month' => (float) $paidThisMonth,
            'issued_this_month' => (float) $issuedThisMonth,
            'invoiced_amount' => (float) $totalBalance->invoicedAmount,
            'paid_amount' => (float) $totalBalance->paidAmount,
            'refunded_amount' => (float) $totalBalance->refundedAmount,
            'debt_amount' => (float) $totalBalance->debtAmount,
            'overpayment_amount' => (float) $totalBalance->overpaymentAmount,

            // Счетчики
            'active_documents_count' => $activeInvoicesCount,
            'paid_documents_count' => $paidInvoicesCount,
            'total_documents_count' => $activeInvoicesCount + $paidInvoicesCount,
        ];
    }

    /**
     * Документы по статусам с суммами
     */
    private function getDocumentsByStatus(int $organizationId, ?int $projectId): array
    {
        $data = $this->documents($organizationId, $projectId)
            ->select(
                'status',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total_sum'),
                DB::raw('SUM(paid_amount) as paid_sum'),
                DB::raw('SUM(remaining_amount) as remaining_sum')
            )
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($item) => is_object($item->status) ? $item->status->value : $item->status)
            ->map(fn ($item) => [
                'count' => $item->count,
                'total_sum' => (float) $item->total_sum,
                'paid_sum' => (float) $item->paid_sum,
                'remaining_sum' => (float) $item->remaining_sum,
            ])
            ->toArray();

        return $data;
    }

    /**
     * Разбивка по типам документов
     */
    private function getDocumentsByType(int $organizationId, ?int $projectId): array
    {
        $data = $this->documents($organizationId, $projectId)
            ->select(
                'document_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total_sum'),
                DB::raw('SUM(paid_amount) as paid_sum')
            )
            ->groupBy('document_type')
            ->get()
            ->keyBy(fn ($item) => is_object($item->document_type) ? $item->document_type->value : $item->document_type)
            ->map(fn ($item) => [
                'count' => $item->count,
                'total_sum' => (float) $item->total_sum,
                'paid_sum' => (float) $item->paid_sum,
                'payment_rate' => $item->total_sum > 0 ? round(($item->paid_sum / $item->total_sum) * 100, 2) : 0,
            ])
            ->toArray();

        return $data;
    }

    /**
     * Просроченные документы
     */
    private function getOverdueDocuments(int $organizationId, ?int $projectId): array
    {
        return $this->documents($organizationId, $projectId)
            ->where(function ($query) {
                $query->whereNotNull('overdue_since')
                    ->orWhere(function ($q) {
                        $q->where('due_date', '<', now())
                            ->whereIn('status', [PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED]);
                    });
            })
            ->with(['project:id,name', 'contractor:id,name', 'counterpartyOrganization:id,name'])
            ->orderBy('due_date', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'document_number' => $doc->document_number,
                    'document_type' => is_object($doc->document_type) ? $doc->document_type->value : $doc->document_type,
                    'invoice_type' => $doc->invoice_type ? (is_object($doc->invoice_type) ? $doc->invoice_type->value : $doc->invoice_type) : null,
                    'direction' => $doc->direction ? (is_object($doc->direction) ? $doc->direction->value : $doc->direction) : null,
                    'amount' => (float) $doc->amount,
                    'remaining_amount' => (float) $doc->remaining_amount,
                    'due_date' => $doc->due_date,
                    'days_overdue' => $doc->due_date ? now()->diffInDays($doc->due_date) : 0,
                    'project_name' => $doc->project?->name ?? 'Без проекта',
                    'counterparty' => $doc->counterpartyOrganization?->name ?? $doc->contractor?->name ?? 'Не указано',
                ];
            })
            ->toArray();
    }

    /**
     * Предстоящие платежи
     */
    private function getUpcomingDocuments(int $organizationId, ?int $projectId): array
    {
        return $this->documents($organizationId, $projectId)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->whereBetween('due_date', [now(), now()->addDays(7)])
            ->with(['project:id,name', 'contractor:id,name'])
            ->orderBy('due_date', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'document_number' => $doc->document_number,
                    'document_type' => is_object($doc->document_type) ? $doc->document_type->value : $doc->document_type,
                    'invoice_type' => $doc->invoice_type ? (is_object($doc->invoice_type) ? $doc->invoice_type->value : $doc->invoice_type) : null,
                    'direction' => $doc->direction ? (is_object($doc->direction) ? $doc->direction->value : $doc->direction) : null,
                    'amount' => (float) $doc->amount,
                    'remaining_amount' => (float) $doc->remaining_amount,
                    'due_date' => $doc->due_date,
                    'days_until_due' => $doc->due_date ? now()->diffInDays($doc->due_date, false) : 0,
                    'project_name' => $doc->project?->name ?? 'Без проекта',
                ];
            })
            ->toArray();
    }

    /**
     * Кэш-флоу за период
     */
    private function getCashFlow(int $organizationId, int $days, ?int $projectId): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        // Входящие платежи
        $incoming = PaymentTransaction::where('payment_transactions.organization_id', $organizationId)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->where('payment_documents.organization_id', $organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('payment_documents.project_id', $projectId))
            ->where('payment_documents.direction', InvoiceDirection::INCOMING)
            ->where('payment_transactions.status', 'completed')
            ->where('payment_transactions.transaction_date', '>=', $startDate)
            ->sum('payment_transactions.amount');

        // Исходящие платежи
        $outgoing = PaymentTransaction::where('payment_transactions.organization_id', $organizationId)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->where('payment_documents.organization_id', $organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('payment_documents.project_id', $projectId))
            ->where('payment_documents.direction', InvoiceDirection::OUTGOING)
            ->where('payment_transactions.status', 'completed')
            ->where('payment_transactions.transaction_date', '>=', $startDate)
            ->sum('payment_transactions.amount');

        return [
            'period_days' => $days,
            'incoming' => (float) $incoming,
            'outgoing' => (float) $outgoing,
            'net_cash_flow' => (float) ($incoming - $outgoing),
            'daily_average_incoming' => $days > 0 ? (float) ($incoming / $days) : 0,
            'daily_average_outgoing' => $days > 0 ? (float) ($outgoing / $days) : 0,
        ];
    }

    /**
     * Топ-должники (кто нам должен)
     */
    private function getTopDebtors(int $organizationId, ?int $projectId): array
    {
        return $this->documents($organizationId, $projectId)
            ->where('direction', InvoiceDirection::INCOMING)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->select(
                DB::raw('COALESCE(contractor_id, counterparty_organization_id) as counterparty_id'),
                DB::raw('CASE 
                    WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = payment_documents.contractor_id)
                    ELSE (SELECT name FROM organizations WHERE id = payment_documents.counterparty_organization_id)
                END as counterparty_name'),
                DB::raw('SUM(remaining_amount) as debt'),
                DB::raw('COUNT(*) as documents_count')
            )
            ->groupBy('counterparty_id', 'contractor_id', 'counterparty_organization_id')
            ->orderByDesc('debt')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                'counterparty_name' => $item->counterparty_name ?? 'Не указано',
                'debt' => (float) $item->debt,
                'documents_count' => $item->documents_count,
            ])
            ->toArray();
    }

    /**
     * Топ-кредиторы (кому мы должны)
     */
    private function getTopCreditors(int $organizationId, ?int $projectId): array
    {
        return $this->documents($organizationId, $projectId)
            ->where('direction', InvoiceDirection::OUTGOING)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->select(
                DB::raw('COALESCE(contractor_id, counterparty_organization_id) as counterparty_id'),
                DB::raw('CASE 
                    WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = payment_documents.contractor_id)
                    ELSE (SELECT name FROM organizations WHERE id = payment_documents.counterparty_organization_id)
                END as counterparty_name'),
                DB::raw('SUM(remaining_amount) as payable'),
                DB::raw('COUNT(*) as documents_count')
            )
            ->groupBy('counterparty_id', 'contractor_id', 'counterparty_organization_id')
            ->orderByDesc('payable')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                'counterparty_name' => $item->counterparty_name ?? 'Не указано',
                'payable' => (float) $item->payable,
                'documents_count' => $item->documents_count,
            ])
            ->toArray();
    }

    /**
     * Разбивка по проектам
     */
    private function getByProjects(int $organizationId, ?int $projectId): array
    {
        return $this->documents($organizationId, $projectId)
            ->leftJoin('projects', 'payment_documents.project_id', '=', 'projects.id')
            ->select(
                'payment_documents.project_id',
                'projects.name as project_name',
                DB::raw('COUNT(*) as documents_count'),
                DB::raw('SUM(payment_documents.amount) as total_sum'),
                DB::raw('SUM(payment_documents.paid_amount) as paid_sum'),
                DB::raw('SUM(payment_documents.remaining_amount) as remaining_sum')
            )
            ->groupBy('payment_documents.project_id', 'projects.name')
            ->orderByDesc('total_sum')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                'project_id' => $item->project_id,
                'project_name' => $item->project_name ?? 'Без проекта',
                'documents_count' => $item->documents_count,
                'total_sum' => (float) $item->total_sum,
                'paid_sum' => (float) $item->paid_sum,
                'remaining_sum' => (float) $item->remaining_sum,
                'payment_rate' => $item->total_sum > 0 ? round(($item->paid_sum / $item->total_sum) * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * Динамика платежей за период
     */
    private function getPaymentTrends(int $organizationId, int $days, ?int $projectId): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        // Группировка по дням
        $trends = PaymentTransaction::where('payment_transactions.organization_id', $organizationId)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->where('payment_documents.organization_id', $organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('payment_documents.project_id', $projectId))
            ->where('payment_transactions.status', 'completed')
            ->where('payment_transactions.transaction_date', '>=', $startDate)
            ->select(
                DB::raw('DATE(payment_transactions.transaction_date) as date'),
                DB::raw('SUM(CASE WHEN payment_documents.direction = \'incoming\' THEN payment_transactions.amount ELSE 0 END) as incoming'),
                DB::raw('SUM(CASE WHEN payment_documents.direction = \'outgoing\' THEN payment_transactions.amount ELSE 0 END) as outgoing')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->date,
                'incoming' => (float) $item->incoming,
                'outgoing' => (float) $item->outgoing,
                'net' => (float) ($item->incoming - $item->outgoing),
            ])
            ->toArray();

        return $trends;
    }

    /**
     * Сравнение с контрактами
     */
    private function getContractComparison(int $organizationId, ?int $projectId): array
    {
        // Общая сумма контрактов
        $contractsTotal = $projectId === null
            ? DB::table('contracts')->where('organization_id', $organizationId)->whereNull('deleted_at')->sum('total_amount')
            : $this->getProjectContractsTotal($organizationId, $projectId);

        // Сумма выставленных документов по контрактам
        $invoicedAmount = $this->documents($organizationId, $projectId)
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->sum('amount');

        // Оплаченная сумма по контрактам
        $paidAmount = $this->documents($organizationId, $projectId)
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->sum('paid_amount');

        return [
            'contracts_total' => (float) $contractsTotal,
            'invoiced_amount' => (float) $invoicedAmount,
            'paid_amount' => (float) $paidAmount,
            'invoiced_percentage' => $contractsTotal > 0 ? round(($invoicedAmount / $contractsTotal) * 100, 2) : 0,
            'paid_percentage' => $contractsTotal > 0 ? round(($paidAmount / $contractsTotal) * 100, 2) : 0,
            'remaining_to_invoice' => (float) max(0, $contractsTotal - $invoicedAmount),
            'remaining_to_pay' => (float) max(0, $invoicedAmount - $paidAmount),
        ];
    }

    private function documents(int $organizationId, ?int $projectId): Builder
    {
        return PaymentDocument::query()
            ->where('payment_documents.organization_id', $organizationId)
            ->when($projectId !== null, fn (Builder $query) => $query->where('payment_documents.project_id', $projectId));
    }

    private function getProjectContractsTotal(int $organizationId, int $projectId): float
    {
        $total = 0.0;
        Contract::query()->where('organization_id', $organizationId)
            ->where(fn (Builder $query) => $query->where('project_id', $projectId)
                ->orWhereHas('projects', fn (Builder $projects) => $projects->where('projects.id', $projectId)))
            ->with(['activeAllocations' => fn ($query) => $query->where('project_id', $projectId)])
            ->withCount('projects')
            ->withSum(['performanceActs as all_approved_amount' => fn ($query) => $query->where('is_approved', true)], 'amount')
            ->withSum(['performanceActs as project_approved_amount' => fn ($query) => $query->where('is_approved', true)->where('project_id', $projectId)], 'amount')
            ->chunkById(200, function ($contracts) use (&$total): void {
                foreach ($contracts as $contract) {
                    $allocation = $contract->activeAllocations->first();
                    if ($allocation && $allocation->allocation_type !== ContractAllocationTypeEnum::AUTO) {
                        $allocation->setRelation('contract', $contract);
                        $total += $allocation->calculateAllocatedAmount();
                        continue;
                    }

                    $amount = (float) $contract->total_amount;
                    if ($contract->is_multi_project) {
                        $allActs = (float) $contract->getAttribute('all_approved_amount');
                        $amount *= $allActs != 0
                            ? (float) $contract->getAttribute('project_approved_amount') / $allActs
                            : 1 / max(1, (int) $contract->getAttribute('projects_count'));
                    }
                    $total += $amount;
                }
            });

        return $total;
    }
}
