<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentConfirmationService;
use App\BusinessModules\Core\Payments\Services\PaymentRecipientNotificationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function trans_message;

class PaymentRecipientController extends Controller
{
    public function __construct(
        private readonly PaymentConfirmationService $confirmationService,
        private readonly PaymentRecipientNotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'status' => ['nullable', 'string'],
                'project_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('projects', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
                'min_amount' => ['nullable', 'numeric', 'min:0'],
                'max_amount' => ['nullable', 'numeric', 'min:0'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $query = $this->recipientDocumentsQuery($organizationId)
                ->with(['payerOrganization', 'payerContractor', 'project', 'approvals'])
                ->orderByDesc('created_at');

            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            if (!empty($validated['project_id'])) {
                $query->where('project_id', $validated['project_id']);
            }

            if (!empty($validated['date_from'])) {
                $query->whereDate('document_date', '>=', $validated['date_from']);
            }

            if (!empty($validated['date_to'])) {
                $query->whereDate('document_date', '<=', $validated['date_to']);
            }

            if (!empty($validated['min_amount'])) {
                $query->where('amount', '>=', $validated['min_amount']);
            }

            if (!empty($validated['max_amount'])) {
                $query->where('amount', '<=', $validated['max_amount']);
            }

            $documents = $query->paginate((int) ($validated['per_page'] ?? 15));

            return AdminResponse::paginated(
                $documents->getCollection()->map(fn ($document) => $this->formatDocument($document)),
                [
                    'current_page' => $documents->currentPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'last_page' => $documents->lastPage(),
                ],
                trans_message('payments.recipient.loaded')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_recipient.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.recipient.load_error'), 500);
        }
    }

    public function show(Request $request, int|string $documentId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $document = $this->recipientDocumentsQuery($organizationId)
                ->with(['payerOrganization', 'payerContractor', 'project', 'approvals', 'transactions'])
                ->findOrFail((int) $documentId);

            return AdminResponse::success(
                $this->formatDocumentDetailed($document),
                trans_message('payments.recipient.loaded')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_recipient.show.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.recipient.show_error'), 500);
        }
    }

    public function markAsViewed(Request $request, int|string $documentId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) $request->user()->id;
            $document = $this->recipientDocumentsQuery($organizationId)->findOrFail((int) $documentId);

            if (!$document->hasRegisteredRecipient()) {
                return AdminResponse::error(trans_message('payments.recipient.recipient_required'), 422);
            }

            $document->markAsViewedByRecipient($userId);

            return AdminResponse::success([
                'viewed_at' => $document->recipient_viewed_at?->toDateTimeString(),
            ], trans_message('payments.recipient.viewed'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_recipient.mark_viewed.error', [
                'document_id' => $documentId,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.recipient.mark_viewed_error'), 500);
        }
    }

    public function confirmReceipt(Request $request, int|string $documentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'comment' => ['nullable', 'string', 'max:1000'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) $request->user()->id;
            $document = $this->recipientDocumentsQuery($organizationId)->findOrFail((int) $documentId);

            $this->confirmationService->confirmReceipt($document, $userId, $validated['comment'] ?? null);
            $document = $document->fresh();

            return AdminResponse::success([
                'confirmed_at' => $document?->recipient_confirmed_at?->toDateTimeString(),
                'comment' => $document?->recipient_confirmation_comment,
            ], trans_message('payments.recipient.confirmed'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_recipient.confirm_receipt.error', [
                'document_id' => $documentId,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.recipient.confirm_error'), 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $baseQuery = $this->recipientDocumentsQuery($organizationId);

            $stats = [
                'total' => (clone $baseQuery)->count(),
                'total_amount' => (float) (clone $baseQuery)->sum('amount'),
                'by_status' => (clone $baseQuery)
                    ->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
                    ->groupBy('status')
                    ->get()
                    ->keyBy(fn ($item) => is_object($item->status) ? $item->status->value : $item->status)
                    ->map(fn ($item) => [
                        'count' => $item->count,
                        'total_amount' => (float) $item->total_amount,
                    ])
                    ->toArray(),
                'pending_confirmation' => (clone $baseQuery)
                    ->whereNotNull('approved_at')
                    ->whereNull('recipient_confirmed_at')
                    ->count(),
                'pending_confirmation_amount' => (float) (clone $baseQuery)
                    ->whereNotNull('approved_at')
                    ->whereNull('recipient_confirmed_at')
                    ->sum('amount'),
                'confirmed' => (clone $baseQuery)
                    ->whereNotNull('recipient_confirmed_at')
                    ->count(),
                'confirmed_amount' => (float) (clone $baseQuery)
                    ->whereNotNull('recipient_confirmed_at')
                    ->sum('amount'),
            ];

            return AdminResponse::success($stats, trans_message('payments.recipient.loaded'));
        } catch (\Exception $e) {
            Log::error('payment_recipient.statistics.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.recipient.statistics_error'), 500);
        }
    }

    private function recipientDocumentsQuery(int $organizationId): Builder
    {
        return PaymentDocument::query()->where(function (Builder $query) use ($organizationId): void {
            $query->where('payee_organization_id', $organizationId)
                ->orWhereHas('payeeContractor', function (Builder $contractorQuery) use ($organizationId): void {
                    $contractorQuery->where('source_organization_id', $organizationId);
                })
                ->orWhere('recipient_organization_id', $organizationId);
        });
    }

    private function formatDocument(PaymentDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'document_type' => $document->document_type->value,
            'document_type_label' => $document->document_type->label(),
            'document_date' => $document->document_date->format('Y-m-d'),
            'due_date' => $document->due_date?->format('Y-m-d'),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'amount' => $document->amount,
            'currency' => $document->currency,
            'payer_name' => $document->getPayerName(),
            'description' => $document->description,
            'is_viewed' => $document->recipient_viewed_at !== null,
            'is_confirmed' => $document->recipient_confirmed_at !== null,
            'viewed_at' => $document->recipient_viewed_at?->toDateTimeString(),
            'confirmed_at' => $document->recipient_confirmed_at?->toDateTimeString(),
            'created_at' => $document->created_at->toDateTimeString(),
        ];
    }

    private function formatDocumentDetailed(PaymentDocument $document): array
    {
        return array_merge($this->formatDocument($document), [
            'paid_amount' => $document->paid_amount,
            'remaining_amount' => $document->remaining_amount,
            'project' => $document->project ? [
                'id' => $document->project->id,
                'name' => $document->project->name,
            ] : null,
            'payment_purpose' => $document->payment_purpose,
            'bank_details' => [
                'account' => $document->bank_account,
                'bik' => $document->bank_bik,
                'correspondent_account' => $document->bank_correspondent_account,
                'bank_name' => $document->bank_name,
            ],
            'approvals' => $document->approvals->map(fn ($approval) => [
                'id' => $approval->id,
                'status' => $approval->status,
                'status_label' => $approval->getStatusLabel(),
                'approval_role' => $approval->approval_role,
                'approval_role_label' => $approval->getRoleLabel(),
                'approver' => $approval->approver ? [
                    'id' => $approval->approver->id,
                    'name' => $approval->approver->name,
                ] : null,
                'decided_at' => $approval->decided_at?->toDateTimeString(),
                'comment' => $approval->decision_comment,
            ]),
            'transactions' => $document->transactions->map(fn ($transaction) => [
                'id' => $transaction->id,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'transaction_date' => $transaction->transaction_date?->toDateString(),
                'payment_method' => $transaction->payment_method,
            ]),
            'problem_flags' => $this->buildProblemFlags($document),
            'workflow_summary' => $this->buildWorkflowSummary($document),
        ]);
    }

    private function buildProblemFlags(PaymentDocument $document): array
    {
        return [
            'is_overdue' => $document->isOverdue(),
            'is_unviewed' => $document->recipient_viewed_at === null,
            'awaiting_confirmation' => $document->recipient_confirmed_at === null && $document->approved_at !== null,
            'missing_bank_details' => empty($document->bank_account) || empty($document->bank_bik),
            'partially_paid' => (float) $document->paid_amount > 0 && (float) $document->remaining_amount > 0,
        ];
    }

    private function buildWorkflowSummary(PaymentDocument $document): array
    {
        $nextAction = null;
        $currentStage = 'created';
        $blockers = [];

        if ($document->recipient_confirmed_at !== null) {
            $currentStage = 'receipt_confirmed';
        } elseif ($document->approved_at !== null) {
            $currentStage = 'awaiting_recipient_confirmation';
            $nextAction = 'confirm_receipt';
        } elseif ($document->recipient_viewed_at !== null) {
            $currentStage = 'viewed_by_recipient';
        } elseif ($document->submitted_at !== null) {
            $currentStage = 'sent_to_recipient';
            $nextAction = 'mark_as_viewed';
        }

        if (empty($document->bank_account) || empty($document->bank_bik)) {
            $blockers[] = 'missing_bank_details';
        }

        if (!$document->hasRegisteredRecipient()) {
            $blockers[] = 'recipient_not_registered';
        }

        return [
            'current_stage' => $currentStage,
            'next_action' => $nextAction,
            'is_blocked' => $blockers !== [],
            'blockers' => $blockers,
        ];
    }
}
