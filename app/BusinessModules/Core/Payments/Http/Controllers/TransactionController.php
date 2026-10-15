<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Http\Requests\RefundPaymentTransactionRequest;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionAccessService;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function trans_message;

class TransactionController extends Controller
{
    public function __construct(
        private readonly PaymentTransactionWorkflowService $transactions,
        private readonly PaymentTransactionAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $actor = $this->actor($request);
            $this->access->assertFilters($organizationId, $actor, $request->all());
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

            $query = $this->access->query($organizationId, $actor, 'payments.transaction.view')->withRefundAvailability($organizationId);

            $paymentDocumentId = $validated['payment_document_id'] ?? $validated['invoice_id'] ?? null;
            if ($paymentDocumentId !== null) {
                $query->where('payment_document_id', $paymentDocumentId);
            }

            if (! empty($validated['project_id'])) {
                $query->where('project_id', $validated['project_id']);
            }

            if (! empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            if (! empty($validated['payment_method'])) {
                $query->where('payment_method', $validated['payment_method']);
            }

            if (! empty($validated['date_from'])) {
                $query->whereDate('transaction_date', '>=', $validated['date_from']);
            }

            if (! empty($validated['date_to'])) {
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
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
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
            $transaction = $this->access->findForResponse($organizationId, $this->actor($request), $id, 'payments.transaction.view');

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
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $actor = $this->actor($request);
            $this->access->find($organizationId, $actor, $id, 'payments.transaction.approve');
            $validated = $request->validate([
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $transaction = $this->transactions->changeStatus($organizationId, $actor, (int) $id, 'approve', $validated);

            return AdminResponse::success(
                $this->formatTransaction($transaction, true),
                trans_message('payments.transactions.approved')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
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
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $actor = $this->actor($request);
            $this->access->find($organizationId, $actor, $id, 'payments.transaction.reject');
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:500'],
            ]);

            $transaction = $this->transactions->changeStatus($organizationId, $actor, (int) $id, 'reject', $validated);

            return AdminResponse::success(
                $this->formatTransaction($transaction, true),
                trans_message('payments.transactions.rejected')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
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

    public function refund(RefundPaymentTransactionRequest $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $payload = $this->transactions->refund($organizationId, $this->actor($request), (int) $id, $validated);

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
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $actor = $this->actor($request);
            $this->access->find($organizationId, $actor, $id, 'payments.transaction.edit');
            $validated = $request->validate([
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            $transaction = $this->transactions->changeStatus($organizationId, $actor, (int) $id, 'cancel', $validated);

            return AdminResponse::success(
                $this->formatTransaction($transaction, true),
                trans_message('payments.transactions.cancelled')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
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
        $refundableAmount = $transaction->refundableAmount();
        $data = [
            'id' => $transaction->id,
            'payment_document_id' => $transaction->payment_document_id,
            'project_id' => $transaction->project_id,
            'amount' => (float) $transaction->amount,
            'refundable_amount' => $refundableAmount,
            'can_be_refunded' => $refundableAmount > 0,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof PaymentTransactionStatus ? $transaction->status->value : $transaction->status,
            'payment_method' => is_object($transaction->payment_method) ? $transaction->payment_method->value : $transaction->payment_method,
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'reference_number' => $transaction->reference_number,
            'bank_transaction_id' => $transaction->bank_transaction_id,
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

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw (new ModelNotFoundException)->setModel(PaymentTransaction::class);
        }

        return $actor;
    }
}
