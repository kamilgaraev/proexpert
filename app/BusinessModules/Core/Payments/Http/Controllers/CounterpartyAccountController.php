<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class CounterpartyAccountController extends Controller
{
    public function show(Request $request, int|string $counterpartyOrganizationId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $counterparty = Organization::query()->findOrFail($counterpartyOrganizationId);

            $ourDebts = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->where('direction', InvoiceDirection::OUTGOING)
                ->whereIn('status', [
                    PaymentDocumentStatus::SUBMITTED,
                    PaymentDocumentStatus::APPROVED,
                    PaymentDocumentStatus::PARTIALLY_PAID,
                    PaymentDocumentStatus::SCHEDULED,
                ])
                ->orderBy('due_date', 'asc')
                ->get();

            $theirDebts = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->where('direction', InvoiceDirection::INCOMING)
                ->whereIn('status', [
                    PaymentDocumentStatus::SUBMITTED,
                    PaymentDocumentStatus::APPROVED,
                    PaymentDocumentStatus::PARTIALLY_PAID,
                    PaymentDocumentStatus::SCHEDULED,
                ])
                ->orderBy('due_date', 'asc')
                ->get();

            $ourDebtAmount = $ourDebts->sum('remaining_amount');
            $theirDebtAmount = $theirDebts->sum('remaining_amount');
            $balance = $theirDebtAmount - $ourDebtAmount;

            $lastTransaction = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->orderBy('updated_at', 'desc')
                ->first();

            return AdminResponse::success([
                'counterparty_organization_id' => (int) $counterpartyOrganizationId,
                'counterparty_name' => $counterparty->name,
                'balance' => (string) $balance,
                'receivable' => (string) $theirDebtAmount,
                'payable' => (string) $ourDebtAmount,
                'last_transaction_date' => $lastTransaction?->updated_at?->toDateString(),
                'documents' => [
                    'our_debts' => $ourDebts->map(fn ($doc) => [
                        'id' => $doc->id,
                        'document_number' => $doc->document_number,
                        'amount' => $doc->amount,
                        'remaining_amount' => $doc->remaining_amount,
                        'due_date' => $doc->due_date,
                    ]),
                    'their_debts' => $theirDebts->map(fn ($doc) => [
                        'id' => $doc->id,
                        'document_number' => $doc->document_number,
                        'amount' => $doc->amount,
                        'remaining_amount' => $doc->remaining_amount,
                        'due_date' => $doc->due_date,
                    ]),
                ],
            ], trans_message('payments.counterparty_account.loaded'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.counterparty_account.show.error', [
                'counterparty_organization_id' => $counterpartyOrganizationId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.counterparty_account.load_error'), 500);
        }
    }
}
