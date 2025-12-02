<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentConfirmationService;
use App\BusinessModules\Core\Payments\Services\PaymentRecipientNotificationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentRecipientController extends Controller
{
    public function __construct(
        private readonly PaymentConfirmationService $confirmationService,
        private readonly PaymentRecipientNotificationService $notificationService
    ) {}

    /**
     * Получить список входящих документов для текущей организации-получателя
     * 
     * @group Payment Recipients
     * @authenticated
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Организация не указана',
                ], 400);
            }

            // Фильтры
            $filters = [
                'status' => $request->input('status'),
                'project_id' => $request->input('project_id'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'min_amount' => $request->input('min_amount'),
                'max_amount' => $request->input('max_amount'),
            ];

            // Получаем документы, где текущая организация является получателем
            $query = PaymentDocument::query()
                ->where(function ($q) use ($organizationId) {
                    // Прямая связь через payee_organization_id
                    $q->where('payee_organization_id', $organizationId)
                        // Или через подрядчика, связанного с организацией
                        ->orWhereHas('payeeContractor', function ($contractorQuery) use ($organizationId) {
                            $contractorQuery->where('source_organization_id', $organizationId);
                        })
                        // Или через recipient_organization_id (кэш)
                        ->orWhere('recipient_organization_id', $organizationId);
                })
                ->with(['payerOrganization', 'payerContractor', 'project', 'approvals'])
                ->orderBy('created_at', 'desc');

            // Применяем фильтры
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['project_id'])) {
                $query->where('project_id', $filters['project_id']);
            }

            if (!empty($filters['date_from'])) {
                $query->where('document_date', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->where('document_date', '<=', $filters['date_to']);
            }

            if (!empty($filters['min_amount'])) {
                $query->where('amount', '>=', $filters['min_amount']);
            }

            if (!empty($filters['max_amount'])) {
                $query->where('amount', '<=', $filters['max_amount']);
            }

            $perPage = min($request->input('per_page', 15), 100);
            $documents = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $documents->map(fn($doc) => $this->formatDocument($doc)),
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'last_page' => $documents->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('payment_recipient.index.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить входящие документы',
            ], 500);
        }
    }

    /**
     * Получить детали входящего документа
     * 
     * @group Payment Recipients
     * @authenticated
     */
    public function show(Request $request, int $documentId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $document = PaymentDocument::where(function ($q) use ($organizationId) {
                $q->where('payee_organization_id', $organizationId)
                    ->orWhereHas('payeeContractor', function ($contractorQuery) use ($organizationId) {
                        $contractorQuery->where('source_organization_id', $organizationId);
                    })
                    ->orWhere('recipient_organization_id', $organizationId);
            })
            ->with(['payerOrganization', 'payerContractor', 'project', 'approvals', 'transactions'])
            ->findOrFail($documentId);

            return response()->json([
                'success' => true,
                'data' => $this->formatDocumentDetailed($document),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Документ не найден или у вас нет доступа',
            ], 404);
        } catch (\Exception $e) {
            Log::error('payment_recipient.show.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить документ',
            ], 500);
        }
    }

    /**
     * Отметить документ как просмотренный получателем
     * 
     * @group Payment Recipients
     * @authenticated
     */
    public function markAsViewed(Request $request, int $documentId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->user()->id;

            $document = PaymentDocument::where(function ($q) use ($organizationId) {
                $q->where('payee_organization_id', $organizationId)
                    ->orWhereHas('payeeContractor', function ($contractorQuery) use ($organizationId) {
                        $contractorQuery->where('source_organization_id', $organizationId);
                    })
                    ->orWhere('recipient_organization_id', $organizationId);
            })
            ->findOrFail($documentId);

            if (!$document->hasRegisteredRecipient()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Получатель не зарегистрирован в системе',
                ], 422);
            }

            $document->markAsViewedByRecipient($userId);

            return response()->json([
                'success' => true,
                'message' => 'Документ отмечен как просмотренный',
                'data' => [
                    'viewed_at' => $document->recipient_viewed_at?->toDateTimeString(),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Документ не найден или у вас нет доступа',
            ], 404);
        } catch (\Exception $e) {
            Log::error('payment_recipient.mark_viewed.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отметить документ как просмотренный',
            ], 500);
        }
    }

    /**
     * Подтвердить получение платежа получателем
     * 
     * @group Payment Recipients
     * @authenticated
     */
    public function confirmReceipt(Request $request, int $documentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'comment' => 'nullable|string|max:1000',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->user()->id;

            $document = PaymentDocument::where(function ($q) use ($organizationId) {
                $q->where('payee_organization_id', $organizationId)
                    ->orWhereHas('payeeContractor', function ($contractorQuery) use ($organizationId) {
                        $contractorQuery->where('source_organization_id', $organizationId);
                    })
                    ->orWhere('recipient_organization_id', $organizationId);
            })
            ->findOrFail($documentId);

            $this->confirmationService->confirmReceipt($document, $userId, $validated['comment'] ?? null);

            return response()->json([
                'success' => true,
                'message' => 'Получение платежа подтверждено',
                'data' => [
                    'confirmed_at' => $document->fresh()->recipient_confirmed_at?->toDateTimeString(),
                    'comment' => $document->fresh()->recipient_confirmation_comment,
                ],
            ]);

        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Документ не найден или у вас нет доступа',
            ], 404);
        } catch (\Exception $e) {
            Log::error('payment_recipient.confirm_receipt.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось подтвердить получение',
            ], 500);
        }
    }

    /**
     * Получить статистику входящих платежей
     * 
     * @group Payment Recipients
     * @authenticated
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $query = PaymentDocument::where(function ($q) use ($organizationId) {
                $q->where('payee_organization_id', $organizationId)
                    ->orWhereHas('payeeContractor', function ($contractorQuery) use ($organizationId) {
                        $contractorQuery->where('source_organization_id', $organizationId);
                    })
                    ->orWhere('recipient_organization_id', $organizationId);
            });

            $stats = [
                'total' => $query->count(),
                'total_amount' => $query->sum('amount'),
                'by_status' => $query->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
                    ->groupBy('status')
                    ->get()
                    ->keyBy('status')
                    ->map(fn($item) => [
                        'count' => $item->count,
                        'total_amount' => (float) $item->total_amount,
                    ]),
                'pending_confirmation' => $query->whereNotNull('approved_at')
                    ->whereNull('recipient_confirmed_at')
                    ->count(),
                'pending_confirmation_amount' => $query->whereNotNull('approved_at')
                    ->whereNull('recipient_confirmed_at')
                    ->sum('amount'),
                'confirmed' => $query->whereNotNull('recipient_confirmed_at')
                    ->count(),
                'confirmed_amount' => $query->whereNotNull('recipient_confirmed_at')
                    ->sum('amount'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('payment_recipient.statistics.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статистику',
            ], 500);
        }
    }

    /**
     * Форматирование документа для списка
     */
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

    /**
     * Форматирование документа (детальный формат)
     */
    private function formatDocumentDetailed(PaymentDocument $document): array
    {
        $basic = $this->formatDocument($document);

        return array_merge($basic, [
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
            'confirmation_comment' => $document->recipient_confirmation_comment,
            'confirmed_by' => $document->recipientConfirmedBy ? [
                'id' => $document->recipientConfirmedBy->id,
                'name' => $document->recipientConfirmedBy->name,
            ] : null,
            'transactions' => $document->transactions->map(fn($t) => [
                'id' => $t->id,
                'amount' => $t->amount,
                'transaction_date' => $t->transaction_date->format('Y-m-d'),
                'reference_number' => $t->reference_number,
                'status' => $t->status->value,
            ]),
        ]);
    }
}

