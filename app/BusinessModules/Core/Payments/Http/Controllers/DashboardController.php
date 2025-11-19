<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Models\Invoice;
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
                    
                    // Счета по статусам (с суммами!)
                    'invoices_by_status' => $this->getInvoicesByStatus($organizationId),
                    
                    // Разбивка по типам счетов
                    'invoices_by_type' => $this->getInvoicesByType($organizationId),
                    
                    // Просроченные счета (топ-10)
                    'overdue_invoices' => $this->getOverdueInvoices($organizationId),
                    
                    // Предстоящие платежи (7 дней)
                    'upcoming_invoices' => $this->getUpcomingInvoices($organizationId),
                    
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
        $totalReceivable = Invoice::where('organization_id', $organizationId)
            ->where('direction', 'incoming')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->sum('remaining_amount');
        
        // Кредиторская задолженность (мы должны)
        $totalPayable = Invoice::where('organization_id', $organizationId)
            ->where('direction', 'outgoing')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->sum('remaining_amount');
        
        // Просроченные платежи
        $overdueAmount = Invoice::where('organization_id', $organizationId)
            ->where('status', 'overdue')
            ->sum('remaining_amount');
        
        // Предстоящие платежи (7 дней)
        $upcomingPayments7days = Invoice::where('organization_id', $organizationId)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereBetween('due_date', [now(), now()->addDays(7)])
            ->sum('remaining_amount');
        
        // Оплачено за текущий месяц
        $paidThisMonth = Invoice::where('organization_id', $organizationId)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('paid_amount');
        
        // Выставлено за текущий месяц
        $issuedThisMonth = Invoice::where('organization_id', $organizationId)
            ->whereNotNull('issued_at')
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total_amount');
        
        // Всего активных счетов
        $activeInvoicesCount = Invoice::where('organization_id', $organizationId)
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->count();
        
        // Всего оплаченных счетов
        $paidInvoicesCount = Invoice::where('organization_id', $organizationId)
            ->where('status', 'paid')
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
            'active_invoices_count' => $activeInvoicesCount,
            'paid_invoices_count' => $paidInvoicesCount,
            'total_invoices_count' => $activeInvoicesCount + $paidInvoicesCount,
        ];
    }
    
    /**
     * Счета по статусам с суммами
     */
    private function getInvoicesByStatus(int $organizationId): array
    {
        $data = Invoice::where('organization_id', $organizationId)
            ->select(
                'status',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_amount) as total_sum'),
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
     * Разбивка по типам счетов
     */
    private function getInvoicesByType(int $organizationId): array
    {
        $data = Invoice::where('organization_id', $organizationId)
            ->select(
                'invoice_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_amount) as total_sum'),
                DB::raw('SUM(paid_amount) as paid_sum')
            )
            ->groupBy('invoice_type')
            ->get()
            ->keyBy(fn($item) => is_object($item->invoice_type) ? $item->invoice_type->value : $item->invoice_type)
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
     * Просроченные счета
     */
    private function getOverdueInvoices(int $organizationId): array
    {
        return Invoice::where('organization_id', $organizationId)
            ->where('status', 'overdue')
            ->with(['project:id,name', 'contractor:id,name'])
            ->orderBy('due_date', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_type' => is_object($invoice->invoice_type) ? $invoice->invoice_type->value : $invoice->invoice_type,
                    'direction' => is_object($invoice->direction) ? $invoice->direction->value : $invoice->direction,
                    'total_amount' => (float) $invoice->total_amount,
                    'remaining_amount' => (float) $invoice->remaining_amount,
                    'due_date' => $invoice->due_date,
                    'days_overdue' => now()->diffInDays($invoice->due_date),
                    'project_name' => $invoice->project?->name ?? 'Без проекта',
                    'counterparty' => $invoice->counterpartyOrganization?->name ?? $invoice->contractor?->name ?? 'Не указано',
                ];
            })
            ->toArray();
    }
    
    /**
     * Предстоящие платежи
     */
    private function getUpcomingInvoices(int $organizationId): array
    {
        return Invoice::where('organization_id', $organizationId)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereBetween('due_date', [now(), now()->addDays(7)])
            ->with(['project:id,name', 'contractor:id,name'])
            ->orderBy('due_date', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_type' => is_object($invoice->invoice_type) ? $invoice->invoice_type->value : $invoice->invoice_type,
                    'direction' => is_object($invoice->direction) ? $invoice->direction->value : $invoice->direction,
                    'total_amount' => (float) $invoice->total_amount,
                    'remaining_amount' => (float) $invoice->remaining_amount,
                    'due_date' => $invoice->due_date,
                    'days_until_due' => now()->diffInDays($invoice->due_date, false),
                    'project_name' => $invoice->project?->name ?? 'Без проекта',
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
        $incoming = PaymentTransaction::where('organization_id', $organizationId)
            ->join('invoices', 'payment_transactions.invoice_id', '=', 'invoices.id')
            ->where('invoices.direction', 'incoming')
            ->where('payment_transactions.status', 'completed')
            ->where('payment_transactions.transaction_date', '>=', $startDate)
            ->sum('payment_transactions.amount');
        
        // Исходящие платежи
        $outgoing = PaymentTransaction::where('organization_id', $organizationId)
            ->join('invoices', 'payment_transactions.invoice_id', '=', 'invoices.id')
            ->where('invoices.direction', 'outgoing')
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
        return Invoice::where('organization_id', $organizationId)
            ->where('direction', 'incoming')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->select(
                DB::raw('COALESCE(contractor_id, counterparty_organization_id) as counterparty_id'),
                DB::raw('CASE 
                    WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = invoices.contractor_id)
                    ELSE (SELECT name FROM organizations WHERE id = invoices.counterparty_organization_id)
                END as counterparty_name'),
                DB::raw('SUM(remaining_amount) as debt'),
                DB::raw('COUNT(*) as invoices_count')
            )
            ->groupBy('counterparty_id', 'contractor_id', 'counterparty_organization_id')
            ->orderByDesc('debt')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'counterparty_name' => $item->counterparty_name ?? 'Не указано',
                'debt' => (float) $item->debt,
                'invoices_count' => $item->invoices_count,
            ])
            ->toArray();
    }
    
    /**
     * Топ-кредиторы (кому мы должны)
     */
    private function getTopCreditors(int $organizationId): array
    {
        return Invoice::where('organization_id', $organizationId)
            ->where('direction', 'outgoing')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->select(
                DB::raw('COALESCE(contractor_id, counterparty_organization_id) as counterparty_id'),
                DB::raw('CASE 
                    WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = invoices.contractor_id)
                    ELSE (SELECT name FROM organizations WHERE id = invoices.counterparty_organization_id)
                END as counterparty_name'),
                DB::raw('SUM(remaining_amount) as payable'),
                DB::raw('COUNT(*) as invoices_count')
            )
            ->groupBy('counterparty_id', 'contractor_id', 'counterparty_organization_id')
            ->orderByDesc('payable')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'counterparty_name' => $item->counterparty_name ?? 'Не указано',
                'payable' => (float) $item->payable,
                'invoices_count' => $item->invoices_count,
            ])
            ->toArray();
    }
    
    /**
     * Разбивка по проектам
     */
    private function getByProjects(int $organizationId): array
    {
        return Invoice::where('invoices.organization_id', $organizationId)
            ->leftJoin('projects', 'invoices.project_id', '=', 'projects.id')
            ->select(
                'invoices.project_id',
                'projects.name as project_name',
                DB::raw('COUNT(*) as invoices_count'),
                DB::raw('SUM(invoices.total_amount) as total_sum'),
                DB::raw('SUM(invoices.paid_amount) as paid_sum'),
                DB::raw('SUM(invoices.remaining_amount) as remaining_sum')
            )
            ->groupBy('invoices.project_id', 'projects.name')
            ->orderByDesc('total_sum')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'project_id' => $item->project_id,
                'project_name' => $item->project_name ?? 'Без проекта',
                'invoices_count' => $item->invoices_count,
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
            ->join('invoices', 'payment_transactions.invoice_id', '=', 'invoices.id')
            ->where('payment_transactions.status', 'completed')
            ->where('payment_transactions.transaction_date', '>=', $startDate)
            ->select(
                DB::raw('DATE(payment_transactions.transaction_date) as date'),
                DB::raw('SUM(CASE WHEN invoices.direction = \'incoming\' THEN payment_transactions.amount ELSE 0 END) as incoming'),
                DB::raw('SUM(CASE WHEN invoices.direction = \'outgoing\' THEN payment_transactions.amount ELSE 0 END) as outgoing')
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
        
        // Сумма выставленных счетов по контрактам
        $invoicedAmount = Invoice::where('organization_id', $organizationId)
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->sum('total_amount');
        
        // Оплаченная сумма по контрактам
        $paidAmount = Invoice::where('organization_id', $organizationId)
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

