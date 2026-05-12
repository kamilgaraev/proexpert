<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function trans_message;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'payment_document_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('payment_documents', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'invoice_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('payment_documents', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'project_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('projects', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'status' => ['nullable', 'string'],
                'payment_method' => ['nullable', 'string'],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $query = PaymentTransaction::query()
                ->where('organization_id', $organizationId)
                ->with(['paymentDocument']);

            $paymentDocumentId = $validated['payment_document_id'] ?? $validated['invoice_id'] ?? null;
            if ($paymentDocumentId !== null) {
                $query->where('payment_document_id', $paymentDocumentId);
            }

            if (!empty($validated['project_id'])) {
                $query->where('project_id', $validated['project_id']);
            }

            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            if (!empty($validated['payment_method'])) {
                $query->where('payment_method', $validated['payment_method']);
            }

            if (!empty($validated['date_from'])) {
                $query->whereDate('transaction_date', '>=', $validated['date_from']);
            }

            if (!empty($validated['date_to'])) {
                $query->whereDate('transaction_date', '<=', $validated['date_to']);
            }

            $transactions = $query->orderByDesc('created_at')->paginate((int) ($validated['per_page'] ?? 15));

            return AdminResponse::paginated(
                $transactions->getCollection()->map(fn ($transaction) => $this->formatTransaction($transaction)),
                [
                    'total' => $transactions->total(),
                    'per_page' => $transactions->perPage(),
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                ],
                trans_message('payments.transactions.loaded')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.transactions.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.transactions.load_error'), 500);
        }
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $transaction = PaymentTransaction::query()
                ->where('organization_id', $organizationId)
                ->with(['paymentDocument', 'createdBy', 'approvedBy'])
                ->findOrFail((int) $id);

            return AdminResponse::success($this->formatTransaction($transaction, true), trans_message('payments.transactions.loaded'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.transactions.show.error', [
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.transactions.load_error'), 500);
        }
    }

    public function approve(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $transaction = PaymentTransaction::query()
                ->where('organization_id', $organizationId)
                ->findOrFail((int) $id);

            if ($transaction->status !== PaymentTransactionStatus::PENDING) {
                return AdminResponse::error(trans_message('payments.transactions.pending_only'), 422);
            }

            $notes = trim((string) $transaction->notes);
            if (!empty($validated['notes'])) {
                $notes = trim($notes . PHP_EOL . $validated['notes']);
            }

            PaymentTransaction::query()->whereKey($transaction->id)->update([
                'status' => PaymentTransactionStatus::COMPLETED->value,
                'approved_by_user_id' => $request->user()->id,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            return AdminResponse::success(
                $this->formatTransaction($this->findTransactionForResponse($transaction->id), true),
                trans_message('payments.transactions.approved')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.transaction.approve.error', [
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.transactions.approve_error'), 500);
        }
    }

    public function reject(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:500'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $transaction = PaymentTransaction::query()
                ->where('organization_id', $organizationId)
                ->findOrFail((int) $id);

            if ($transaction->status !== PaymentTransactionStatus::PENDING) {
                return AdminResponse::error(trans_message('payments.transactions.pending_only'), 422);
            }

            PaymentTransaction::query()->whereKey($transaction->id)->update([
                'status' => PaymentTransactionStatus::FAILED->value,
                'notes' => $this->appendNote(
                    $transaction->notes,
                    trans_message('payments.transactions.reject_note', ['reason' => $validated['reason']])
                ),
            ]);

            return AdminResponse::success(
                $this->formatTransaction($this->findTransactionForResponse($transaction->id), true),
                trans_message('payments.transactions.rejected')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.transaction.reject.error', [
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.transactions.reject_error'), 500);
        }
    }

    public function refund(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'amount' => ['nullable', 'numeric', 'min:0.01'],
                'reason' => ['required', 'string', 'max:500'],
                'refund_date' => ['nullable', 'date'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) $request->user()->id;

            $payload = DB::transaction(function () use ($organizationId, $userId, $validated, $id): array {
                $originalTransaction = PaymentTransaction::query()
                    ->where('organization_id', $organizationId)
                    ->with('paymentDocument')
                    ->lockForUpdate()
                    ->findOrFail((int) $id);

                if ($originalTransaction->status !== PaymentTransactionStatus::COMPLETED) {
                    throw new \DomainException(trans_message('payments.transactions.completed_only'));
                }

                $refundAmount = (float) ($validated['amount'] ?? $originalTransaction->amount);
                if ($refundAmount > (float) $originalTransaction->amount) {
                    throw new \DomainException(trans_message('payments.transactions.refund_amount_invalid'));
                }

                $refundTransaction = PaymentTransaction::create([
                    'payment_document_id' => $originalTransaction->payment_document_id,
                    'organization_id' => $organizationId,
                    'project_id' => $originalTransaction->project_id,
                    'amount' => -$refundAmount,
                    'currency' => $originalTransaction->currency,
                    'payment_method' => $originalTransaction->payment_method,
                    'transaction_date' => $validated['refund_date'] ?? now()->toDateString(),
                    'status' => PaymentTransactionStatus::COMPLETED->value,
                    'notes' => trans_message('payments.transactions.refund_note', ['reason' => $validated['reason']]),
                    'created_by_user_id' => $userId,
                    'approved_by_user_id' => $userId,
                    'metadata' => [
                        'original_transaction_id' => $originalTransaction->id,
                        'refund_reason' => $validated['reason'],
                    ],
                ]);

                PaymentTransaction::query()->whereKey($originalTransaction->id)->update([
                    'status' => PaymentTransactionStatus::REFUNDED->value,
                ]);

                if ($originalTransaction->paymentDocument !== null) {
                    $document = $originalTransaction->paymentDocument;
                    $document->paid_amount -= $refundAmount;
                    $document->remaining_amount += $refundAmount;

                    app(PaymentDocumentService::class)->updateStatus($document);
                    $document->save();
                }

                return [
                    'original_transaction' => $this->findTransactionForResponse($originalTransaction->id),
                    'refund_transaction' => $refundTransaction->fresh(['paymentDocument', 'createdBy', 'approvedBy']),
                ];
            });

            return AdminResponse::success([
                'original_transaction' => $this->formatTransaction($payload['original_transaction'], true),
                'refund_transaction' => $this->formatTransaction($payload['refund_transaction'], true),
            ], trans_message('payments.transactions.refunded'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.transaction.refund.error', [
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.transactions.refund_error'), 500);
        }
    }

    public function cancel(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $transaction = PaymentTransaction::query()
                ->where('organization_id', $organizationId)
                ->findOrFail((int) $id);

            if (!in_array($transaction->status, [PaymentTransactionStatus::PENDING, PaymentTransactionStatus::PROCESSING], true)) {
                return AdminResponse::error(trans_message('payments.transactions.cancel_forbidden'), 422);
            }

            $reason = $validated['reason'] ?? null;
            $notes = $this->appendNote(
                $transaction->notes,
                $reason
                    ? trans_message('payments.transactions.cancel_note', ['reason' => $reason])
                    : trans_message('payments.transactions.cancel_note_without_reason')
            );

            PaymentTransaction::query()->whereKey($transaction->id)->update([
                'status' => PaymentTransactionStatus::CANCELLED->value,
                'notes' => $notes,
            ]);

            return AdminResponse::success(
                $this->formatTransaction($this->findTransactionForResponse($transaction->id), true),
                trans_message('payments.transactions.cancelled')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.transaction.cancel.error', [
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.transactions.cancel_error'), 500);
        }
    }

    private function formatTransaction(PaymentTransaction $transaction, bool $detailed = false): array
    {
        $data = [
            'id' => $transaction->id,
            'payment_document_id' => $transaction->payment_document_id,
            'project_id' => $transaction->project_id,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof PaymentTransactionStatus ? $transaction->status->value : $transaction->status,
            'payment_method' => is_object($transaction->payment_method) ? $transaction->payment_method->value : $transaction->payment_method,
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'notes' => $transaction->notes,
            'metadata' => $transaction->metadata,
            'created_at' => $transaction->created_at?->toDateTimeString(),
        ];

        if ($detailed) {
            $data['payment_document'] = $transaction->paymentDocument ? [
                'id' => $transaction->paymentDocument->id,
                'document_number' => $transaction->paymentDocument->document_number,
                'status' => $transaction->paymentDocument->status->value,
                'amount' => $transaction->paymentDocument->amount,
            ] : null;
            $data['created_by_user'] = $transaction->createdBy ? [
                'id' => $transaction->createdBy->id,
                'name' => $transaction->createdBy->name,
            ] : null;
            $data['approved_by_user'] = $transaction->approvedBy ? [
                'id' => $transaction->approvedBy->id,
                'name' => $transaction->approvedBy->name,
            ] : null;
        }

        return $data;
    }

    private function findTransactionForResponse(int $transactionId): PaymentTransaction
    {
        return PaymentTransaction::query()
            ->with(['paymentDocument', 'createdBy', 'approvedBy'])
            ->findOrFail($transactionId);
    }

    private function appendNote(?string $currentNote, string $newNote): string
    {
        return trim(trim((string) $currentNote) . PHP_EOL . $newNote);
    }
}
