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
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            $counterpartyIds = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->whereNotNull('counterparty_organization_id')
                ->whereIn('status', $this->debtStatuses())
                ->distinct()
                ->pluck('counterparty_organization_id');

            $counterparties = Organization::query()
                ->whereIn('id', $counterpartyIds)
                ->orderBy('name')
                ->get()
                ->keyBy('id');

            $accounts = $counterpartyIds
                ->map(fn ($counterpartyId) => $this->buildAccountPayload($organizationId, (int) $counterpartyId, $counterparties->get($counterpartyId)))
                ->filter()
                ->sortBy('counterparty_name')
                ->values()
                ->all();

            return AdminResponse::success($accounts, trans_message('payments.counterparty_account.loaded'));
        } catch (\Exception $e) {
            Log::error('payments.counterparty_account.index.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.counterparty_account.load_error'), 500);
        }
    }

    public function show(Request $request, int|string $counterpartyOrganizationId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $counterparty = Organization::query()->findOrFail($counterpartyOrganizationId);

            return AdminResponse::success(
                $this->buildAccountPayload($organizationId, (int) $counterpartyOrganizationId, $counterparty),
                trans_message('payments.counterparty_account.loaded')
            );
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

    private function buildAccountPayload(int $organizationId, int $counterpartyOrganizationId, ?Organization $counterparty): ?array
    {
        if ($counterparty === null) {
            return null;
        }

        $ourDebts = $this->debtDocumentsQuery($organizationId, $counterpartyOrganizationId, InvoiceDirection::OUTGOING)
            ->orderBy('due_date', 'asc')
            ->get();

        $theirDebts = $this->debtDocumentsQuery($organizationId, $counterpartyOrganizationId, InvoiceDirection::INCOMING)
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

        $documents = [
            'our_debts' => $ourDebts->map(fn (PaymentDocument $doc) => $this->formatDebtDocument($doc))->values(),
            'their_debts' => $theirDebts->map(fn (PaymentDocument $doc) => $this->formatDebtDocument($doc))->values(),
        ];

        return [
            'counterparty_organization_id' => $counterpartyOrganizationId,
            'counterparty_name' => $counterparty->name,
            'balance' => (string) $balance,
            'receivable' => (string) $theirDebtAmount,
            'payable' => (string) $ourDebtAmount,
            'last_transaction_date' => $lastTransaction?->updated_at?->toDateString(),
            'documents' => $documents,
            'invoices' => $documents,
        ];
    }

    private function debtDocumentsQuery(int $organizationId, int $counterpartyOrganizationId, InvoiceDirection $direction): \Illuminate\Database\Eloquent\Builder
    {
        return PaymentDocument::query()
            ->where('organization_id', $organizationId)
            ->where('counterparty_organization_id', $counterpartyOrganizationId)
            ->where('direction', $direction)
            ->whereIn('status', $this->debtStatuses());
    }

    private function debtStatuses(): array
    {
        return [
            PaymentDocumentStatus::SUBMITTED,
            PaymentDocumentStatus::APPROVED,
            PaymentDocumentStatus::PARTIALLY_PAID,
            PaymentDocumentStatus::SCHEDULED,
        ];
    }

    private function formatDebtDocument(PaymentDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'amount' => $document->amount,
            'remaining_amount' => $document->remaining_amount,
            'due_date' => $document->due_date?->toDateString(),
        ];
    }
}
