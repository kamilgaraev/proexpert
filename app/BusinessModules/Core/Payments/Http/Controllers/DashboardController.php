<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * Получить финансовую статистику дашборда
     * 
     * GET /api/v1/admin/payments/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $period = $request->input('period', '30'); // дней для анализа
            
            return response()->json([
                'success' => true,
                'data' => [
                    // Основная финансовая сводка
                    'summary' => $this->getSummary($organizationId),
                    
                    // Документы по статусам (с суммами!)
                    'documents_by_status' => $this->getDocumentsByStatus($organizationId),
                    
                    // Разбивка по типам документов
                    'documents_by_type' => $this->getDocumentsByType($organizationId),
                    
                    // Просроченные документы (топ-10)
                    'overdue_documents' => $this->getOverdueDocuments($organizationId),
                    
                    // Предстоящие платежи (7 дней)
                    'upcoming_documents' => $this->getUpcomingDocuments($organizationId),
                    
                    // Кэш-флоу за период
                    'cash_flow' => $this->getCashFlow($organizationId, $period),
                    
                    // Топ-контрагенты (должники)
                    'top_debtors' => $this->getTopDebtors($organizationId),
                    
                    // Топ-контрагенты (кому мы должны)
                    'top_creditors' => $this->getTopCreditors($organizationId),
                    
                    // Разбивка по проектам
                    'by_projects' => $this->getByProjects($organizationId),
                    
                    // Динамика платежей за период
                    'payment_trends' => $this->getPaymentTrends($organizationId, $period),
                    
                    // Сравнение с контрактами
                    'contract_comparison' => $this->getContractComparison($organizationId),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.dashboard.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить дашборд',
            ], 500);
        }
    }
    
    /**
     * Получить общую статистику
     */
    private function getSummary(int $organizationId): array
    {
        // Дебиторская задолженность (нам должны)
        $totalReceivable = PaymentDocument::where('organization_id', $organizationId)
            ->where('direction', InvoiceDirection::INCOMING)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->sum('remaining_amount');
        
        // Кредиторская задолженность (мы должны)
        $totalPayable = PaymentDocument::where('organization_id', $organizationId)
            ->where('direction', InvoiceDirection::OUTGOING)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->sum('remaining_amount');
        
        // Просроченные платежи
        $overdueAmount = PaymentDocument::where('organization_id', $organizationId)
            ->where('overdue_since', '!=', null)
            ->orWhere(function ($query) {
                $query->where('due_date', '<', now())
                    ->whereIn('status', [PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED]);
            })
            ->sum('remaining_amount');
        
        // Предстоящие платежи (7 дней)
        $upcomingPayments7days = PaymentDocument::where('organization_id', $organizationId)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->whereBetween('due_date', [now(), now()->addDays(7)])
            ->sum('remaining_amount');
        
        // Оплачено за текущий месяц
        $paidThisMonth = PaymentDocument::where('organization_id', $organizationId)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('paid_amount');
        
        // Выставлено за текущий месяц
        $issuedThisMonth = PaymentDocument::where('organization_id', $organizationId)
            ->whereNotNull('issued_at')
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');
        
        // Всего активных документов
        $activeInvoicesCount = PaymentDocument::where('organization_id', $organizationId)
            ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
            ->count();
        
        // Всего оплаченных документов
        $paidInvoicesCount = PaymentDocument::where('organization_id', $organizationId)
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
            
            // Счетчики
            'active_documents_count' => $activeInvoicesCount,
            'paid_documents_count' => $paidInvoicesCount,
            'total_documents_count' => $activeInvoicesCount + $paidInvoicesCount,
        ];
    }
    
    /**
     * Документы по статусам с суммами
     */
    private function getDocumentsByStatus(int $organizationId): array
    {
        $data = PaymentDocument::where('organization_id', $organizationId)
            ->select(
                'status',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total_sum'),
                DB::raw('SUM(paid_amount) as paid_sum'),
                DB::raw('SUM(remaining_amount) as remaining_sum')
            )
            ->groupBy('status')
            ->get()
            ->keyBy(fn($item) => is_object($item->status) ? $item->status->value : $item->status)
            ->map(fn($item) => [
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
    private function getDocumentsByType(int $organizationId): array
    {
        $data = PaymentDocument::where('organization_id', $organizationId)
            ->select(
                'document_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total_sum'),
                DB::raw('SUM(paid_amount) as paid_sum')
            )
            ->groupBy('document_type')
            ->get()
            ->keyBy(fn($item) => is_object($item->document_type) ? $item->document_type->value : $item->document_type)
            ->map(fn($item) => [
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
    private function getOverdueDocuments(int $organizationId): array
    {
        return PaymentDocument::where('organization_id', $organizationId)
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
    private function getUpcomingDocuments(int $organizationId): array
    {
        return PaymentDocument::where('organization_id', $organizationId)
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
    private function getCashFlow(int $organizationId, int $days): array
    {
        $startDate = now()->subDays($days)->startOfDay();
        
        // Входящие платежи
        $incoming = PaymentTransaction::where('payment_transactions.organization_id', $organizationId)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->where('payment_documents.direction', InvoiceDirection::INCOMING)
            ->where('payment_transactions.status', 'completed')
            ->where('payment_transactions.transaction_date', '>=', $startDate)
            ->sum('payment_transactions.amount');
        
        // Исходящие платежи
        $outgoing = PaymentTransaction::where('payment_transactions.organization_id', $organizationId)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
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
    private function getTopDebtors(int $organizationId): array
    {
        return PaymentDocument::where('organization_id', $organizationId)
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
            ->map(fn($item) => [
                'counterparty_name' => $item->counterparty_name ?? 'Не указано',
                'debt' => (float) $item->debt,
                'documents_count' => $item->documents_count,
            ])
            ->toArray();
    }
    
    /**
     * Топ-кредиторы (кому мы должны)
     */
    private function getTopCreditors(int $organizationId): array
    {
        return PaymentDocument::where('organization_id', $organizationId)
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
            ->map(fn($item) => [
                'counterparty_name' => $item->counterparty_name ?? 'Не указано',
                'payable' => (float) $item->payable,
                'documents_count' => $item->documents_count,
            ])
            ->toArray();
    }
    
    /**
     * Разбивка по проектам
     */
    private function getByProjects(int $organizationId): array
    {
        return PaymentDocument::where('payment_documents.organization_id', $organizationId)
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
            ->map(fn($item) => [
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
    private function getPaymentTrends(int $organizationId, int $days): array
    {
        $startDate = now()->subDays($days)->startOfDay();
        
        // Группировка по дням
        $trends = PaymentTransaction::where('payment_transactions.organization_id', $organizationId)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
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
            ->map(fn($item) => [
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
    private function getContractComparison(int $organizationId): array
    {
        // Общая сумма контрактов
        $contractsTotal = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->sum('total_amount');
        
        // Сумма выставленных документов по контрактам
        $invoicedAmount = PaymentDocument::where('organization_id', $organizationId)
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->sum('amount');
        
        // Оплаченная сумма по контрактам
        $paidAmount = PaymentDocument::where('organization_id', $organizationId)
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
}

