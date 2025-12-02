<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
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
            $ourDebts = PaymentDocument::where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->where('direction', InvoiceDirection::OUTGOING)
                ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
                ->orderBy('due_date', 'asc')
                ->get();
            
            // Их долги (контрагент должен нам)
            $theirDebts = PaymentDocument::where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->where('direction', InvoiceDirection::INCOMING)
                ->whereIn('status', [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::SCHEDULED])
                ->orderBy('due_date', 'asc')
                ->get();
            
            $ourDebtAmount = $ourDebts->sum('remaining_amount');
            $theirDebtAmount = $theirDebts->sum('remaining_amount');
            $balance = $theirDebtAmount - $ourDebtAmount;
            
            // Последняя транзакция
            $lastTransaction = PaymentDocument::where('organization_id', $organizationId)
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
                    'documents' => [
                        'our_debts' => $ourDebts->map(function ($doc) {
                            return [
                                'id' => $doc->id,
                                'document_number' => $doc->document_number,
                                'amount' => $doc->amount,
                                'remaining_amount' => $doc->remaining_amount,
                                'due_date' => $doc->due_date,
                            ];
                        }),
                        'their_debts' => $theirDebts->map(function ($doc) {
                            return [
                                'id' => $doc->id,
                                'document_number' => $doc->document_number,
                                'amount' => $doc->amount,
                                'remaining_amount' => $doc->remaining_amount,
                                'due_date' => $doc->due_date,
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

