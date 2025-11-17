<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CounterpartyAccountController extends Controller
{
    /**
     * Получить взаиморасчёты с контрагентом
     * 
     * GET /api/v1/admin/payments/counterparty-accounts/{organizationId}
     */
    public function show(Request $request, int $counterpartyOrganizationId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $counterparty = Organization::findOrFail($counterpartyOrganizationId);
            
            // Наши долги (мы должны контрагенту)
            $ourDebts = Invoice::where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->where('direction', 'outgoing')
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->orderBy('due_date', 'asc')
                ->get();
            
            // Их долги (контрагент должен нам)
            $theirDebts = Invoice::where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->where('direction', 'incoming')
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->orderBy('due_date', 'asc')
                ->get();
            
            $ourDebtAmount = $ourDebts->sum('remaining_amount');
            $theirDebtAmount = $theirDebts->sum('remaining_amount');
            $balance = $theirDebtAmount - $ourDebtAmount;
            
            // Последняя транзакция
            $lastTransaction = Invoice::where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->orderBy('updated_at', 'desc')
                ->first();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'counterparty_organization_id' => $counterpartyOrganizationId,
                    'counterparty_name' => $counterparty->name,
                    'balance' => (string) $balance,
                    'receivable' => (string) $theirDebtAmount,
                    'payable' => (string) $ourDebtAmount,
                    'last_transaction_date' => $lastTransaction?->updated_at?->toDateString(),
                    'invoices' => [
                        'our_debts' => $ourDebts->map(function ($invoice) {
                            return [
                                'id' => $invoice->id,
                                'invoice_number' => $invoice->invoice_number,
                                'total_amount' => $invoice->total_amount,
                                'remaining_amount' => $invoice->remaining_amount,
                                'due_date' => $invoice->due_date,
                            ];
                        }),
                        'their_debts' => $theirDebts->map(function ($invoice) {
                            return [
                                'id' => $invoice->id,
                                'invoice_number' => $invoice->invoice_number,
                                'total_amount' => $invoice->total_amount,
                                'remaining_amount' => $invoice->remaining_amount,
                                'due_date' => $invoice->due_date,
                            ];
                        }),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.counterparty_account.show.error', [
                'counterparty_organization_id' => $counterpartyOrganizationId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить данные контрагента',
            ], 500);
        }
    }
}

