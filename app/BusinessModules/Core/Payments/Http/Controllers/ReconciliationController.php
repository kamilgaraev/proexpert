<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ReconciliationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'counterparty_organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')],
                'period_from' => ['required', 'date'],
                'period_to' => ['required', 'date', 'after_or_equal:period_from'],
                'include_paid' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);
            $counterpartyOrganizationId = (int) $validated['counterparty_organization_id'];

            $counterparty = Organization::query()->findOrFail($counterpartyOrganizationId);

            $query = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->whereBetween('document_date', [
                    $validated['period_from'],
                    $validated['period_to'],
                ]);

            if (!($validated['include_paid'] ?? false)) {
                $query->whereIn('status', [
                    PaymentDocumentStatus::SUBMITTED,
                    PaymentDocumentStatus::APPROVED,
                    PaymentDocumentStatus::PARTIALLY_PAID,
                    PaymentDocumentStatus::SCHEDULED,
                ]);
            }

            $documents = $query->get();
            $ourDebts = $documents->where('direction', 'outgoing')->sum('remaining_amount');
            $theirDebts = $documents->where('direction', 'incoming')->sum('remaining_amount');
            $netBalance = (float) $theirDebts - (float) $ourDebts;

            $reconciliationNumber = 'RECON-' . now()->format('Y') . '-' . str_pad(
                (string) (PaymentDocument::query()->whereYear('created_at', now()->year)->count() + 1),
                3,
                '0',
                STR_PAD_LEFT
            );

            $data = [
                'reconciliation_number' => $reconciliationNumber,
                'counterparty' => $counterparty->name,
                'period_from' => $validated['period_from'],
                'period_to' => $validated['period_to'],
                'our_balance' => (string) (-$ourDebts),
                'their_balance' => (string) $theirDebts,
                'net_balance' => (string) $netBalance,
                'documents_count' => $documents->count(),
                'transactions_count' => $documents->sum(fn ($doc) => $doc->transactions()->count()),
                'summary' => [
                    'include_paid' => (bool) ($validated['include_paid'] ?? false),
                    'has_discrepancy' => abs($netBalance) > 0.01,
                    'notes' => $validated['notes'] ?? null,
                ],
                'documents_preview' => $documents
                    ->take(10)
                    ->map(fn (PaymentDocument $document) => [
                        'id' => $document->id,
                        'document_number' => $document->document_number,
                        'document_date' => $document->document_date?->format('Y-m-d'),
                        'status' => $document->status->value,
                        'direction' => $document->direction instanceof \BackedEnum
                            ? $document->direction->value
                            : $document->direction,
                        'amount' => (string) $document->amount,
                        'remaining_amount' => (string) $document->remaining_amount,
                    ])
                    ->values()
                    ->all(),
            ];

            Log::info('payments.reconciliation.created', $data);

            return AdminResponse::success($data, trans_message('payments.reconciliation.created'));
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.reconciliation.store.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.reconciliation.create_error'), 500);
        }
    }
}
