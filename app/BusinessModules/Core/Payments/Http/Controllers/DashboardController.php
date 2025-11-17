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
            
            // Общая статистика
            $summary = $this->getSummary($organizationId);
            
            // Счета по статусам
            $invoicesByStatus = Invoice::where('organization_id', $organizationId)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();
            
            // Просроченные счета
            $overdueInvoices = Invoice::where('organization_id', $organizationId)
                ->where('status', 'overdue')
                ->orderBy('due_date', 'asc')
                ->limit(10)
                ->get()
                ->map(function ($invoice) {
                    return [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'total_amount' => $invoice->total_amount,
                        'days_overdue' => now()->diffInDays($invoice->due_date),
                        'counterparty' => $invoice->counterpartyOrganization?->name ?? $invoice->contractor?->name ?? 'Не указано',
                    ];
                });
            
            // Предстоящие платежи (в ближайшие 7 дней)
            $upcomingInvoices = Invoice::where('organization_id', $organizationId)
                ->whereIn('status', ['issued', 'partially_paid'])
                ->whereBetween('due_date', [now(), now()->addDays(7)])
                ->orderBy('due_date', 'asc')
                ->limit(10)
                ->get()
                ->map(function ($invoice) {
                    return [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'total_amount' => $invoice->total_amount,
                        'remaining_amount' => $invoice->remaining_amount,
                        'due_date' => $invoice->due_date,
                        'days_until_due' => now()->diffInDays($invoice->due_date, false),
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'invoices_by_status' => $invoicesByStatus,
                    'overdue_invoices' => $overdueInvoices,
                    'upcoming_invoices' => $upcomingInvoices,
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
        
        return [
            'total_receivable' => (string) $totalReceivable,
            'total_payable' => (string) $totalPayable,
            'overdue_amount' => (string) $overdueAmount,
            'upcoming_payments_7days' => (string) $upcomingPayments7days,
        ];
    }
}

