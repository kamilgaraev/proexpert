<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentDocumentController extends Controller
{
    public function __construct(
        private readonly PaymentDocumentService $service
    ) {}

    /**
     * Список платежных документов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $filters = [
                'document_type' => $request->input('document_type'),
                'status' => $request->input('status'),
                'project_id' => $request->input('project_id'),
                'contract_id' => $request->input('contract_id'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'amount_from' => $request->input('amount_from'),
                'amount_to' => $request->input('amount_to'),
                'search' => $request->input('search'),
                'sort_by' => $request->input('sort_by', 'created_at'),
                'sort_order' => $request->input('sort_order', 'desc'),
            ];

            $documents = $this->service->getForOrganization($organizationId, $filters);

            return response()->json([
                'success' => true,
                'data' => $documents->map(fn($doc) => $this->formatDocument($doc)),
                'meta' => [
                    'total' => $documents->count(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_document.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить документы',
            ], 500);
        }
    }

    /**
     * Получить конкретный документ
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $document = PaymentDocument::forOrganization($organizationId)
                ->with(['project', 'payerOrganization', 'payeeOrganization', 'payerContractor', 'payeeContractor', 'source', 'approvals', 'transactions'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->formatDocumentDetailed($document),
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_document.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Документ не найден',
            ], 404);
        }
    }

    /**
     * Создать платежный документ
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'document_type' => 'required|string|in:payment_request,invoice,payment_order,incoming_payment,expense,offset_act',
                'document_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'project_id' => 'nullable|integer|exists:projects,id',
                'payer_organization_id' => 'nullable|integer|exists:organizations,id',
                'payer_contractor_id' => 'nullable|integer|exists:contractors,id',
                'payee_organization_id' => 'nullable|integer|exists:organizations,id',
                'payee_contractor_id' => 'nullable|integer|exists:contractors,id',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|size:3',
                'vat_rate' => 'nullable|numeric|min:0|max:100',
                'source_type' => 'nullable|string',
                'source_id' => 'nullable|integer',
                'description' => 'nullable|string',
                'payment_purpose' => 'nullable|string',
                'bank_account' => 'nullable|string|size:20',
                'bank_bik' => 'nullable|string|size:9',
                'bank_correspondent_account' => 'nullable|string|size:20',
                'bank_name' => 'nullable|string',
                'attached_documents' => 'nullable|array',
                'metadata' => 'nullable|array',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->user()->id;

            $validated['organization_id'] = $organizationId;
            $validated['created_by_user_id'] = $userId;

            $document = $this->service->create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Платежный документ создан',
                'data' => $this->formatDocumentDetailed($document),
            ], 201);
        } catch (\DomainException | \InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_document.store.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать документ',
            ], 500);
        }
    }

    /**
     * Обновить платежный документ
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $validated = $request->validate([
                'document_date' => 'sometimes|date',
                'due_date' => 'sometimes|date',
                'project_id' => 'sometimes|nullable|integer|exists:projects,id',
                'amount' => 'sometimes|numeric|min:0.01',
                'vat_rate' => 'sometimes|numeric|min:0|max:100',
                'description' => 'sometimes|nullable|string',
                'payment_purpose' => 'sometimes|nullable|string',
                'bank_account' => 'sometimes|nullable|string|size:20',
                'bank_bik' => 'sometimes|nullable|string|size:9',
                'bank_correspondent_account' => 'sometimes|nullable|string|size:20',
                'bank_name' => 'sometimes|nullable|string',
                'attached_documents' => 'sometimes|nullable|array',
                'notes' => 'sometimes|nullable|string',
            ]);

            $updated = $this->service->update($document, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Документ обновлен',
                'data' => $this->formatDocumentDetailed($updated),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_document.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить документ',
            ], 500);
        }
    }

    /**
     * Отправить на утверждение
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $submitted = $this->service->submit($document);

            return response()->json([
                'success' => true,
                'message' => 'Документ отправлен на утверждение',
                'data' => $this->formatDocumentDetailed($submitted),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_document.submit.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отправить на утверждение',
            ], 500);
        }
    }

    /**
     * Запланировать платеж
     */
    public function schedule(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'scheduled_at' => 'nullable|date',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $scheduledAt = isset($validated['scheduled_at']) 
                ? new \DateTime($validated['scheduled_at']) 
                : null;

            $scheduled = $this->service->schedule($document, $scheduledAt);

            return response()->json([
                'success' => true,
                'message' => 'Платеж запланирован',
                'data' => $this->formatDocumentDetailed($scheduled),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_document.schedule.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось запланировать платеж',
            ], 500);
        }
    }

    /**
     * Зарегистрировать платеж
     */
    public function registerPayment(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'nullable|string',
                'reference_number' => 'nullable|string',
                'bank_transaction_id' => 'nullable|string',
                'transaction_date' => 'nullable|date',
                'notes' => 'nullable|string',
                'metadata' => 'nullable|array',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->user()->id;

            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $validated['created_by_user_id'] = $userId;
            $paid = $this->service->registerPayment($document, $validated['amount'], $validated);

            return response()->json([
                'success' => true,
                'message' => 'Платеж зарегистрирован',
                'data' => $this->formatDocumentDetailed($paid),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_document.register_payment.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось зарегистрировать платеж',
            ], 500);
        }
    }

    /**
     * Отменить документ
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => 'required|string|min:3',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $cancelled = $this->service->cancel($document, $validated['reason']);

            return response()->json([
                'success' => true,
                'message' => 'Документ отменен',
                'data' => $this->formatDocumentDetailed($cancelled),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_document.cancel.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отменить документ',
            ], 500);
        }
    }

    /**
     * Удалить документ
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $this->service->delete($document);

            return response()->json([
                'success' => true,
                'message' => 'Документ удален',
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_document.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить документ',
            ], 500);
        }
    }

    /**
     * Получить просроченные документы
     */
    public function overdue(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $documents = $this->service->getOverdue($organizationId);

            return response()->json([
                'success' => true,
                'data' => $documents->map(fn($doc) => $this->formatDocument($doc)),
                'meta' => [
                    'total' => $documents->count(),
                    'total_amount' => $documents->sum('remaining_amount'),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_document.overdue.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить просроченные документы',
            ], 500);
        }
    }

    /**
     * Получить предстоящие платежи
     */
    public function upcoming(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $days = $request->input('days', 7);

            $documents = $this->service->getUpcoming($organizationId, $days);

            return response()->json([
                'success' => true,
                'data' => $documents->map(fn($doc) => $this->formatDocument($doc)),
                'meta' => [
                    'total' => $documents->count(),
                    'total_amount' => $documents->sum('remaining_amount'),
                    'days' => $days,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_document.upcoming.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить предстоящие платежи',
            ], 500);
        }
    }

    /**
     * Статистика по документам
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $stats = $this->service->getStatistics($organizationId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_document.statistics.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статистику',
            ], 500);
        }
    }

    /**
     * Форматирование документа (краткий формат)
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
            'paid_amount' => $document->paid_amount,
            'remaining_amount' => $document->remaining_amount,
            'currency' => $document->currency,
            'payer_name' => $document->getPayerName(),
            'payee_name' => $document->getPayeeName(),
            'project' => $document->project ? [
                'id' => $document->project->id,
                'name' => $document->project->name,
            ] : null,
            'is_overdue' => $document->isOverdue(),
            'days_until_due' => $document->getDaysUntilDue(),
            'payment_percentage' => $document->getPaymentPercentage(),
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
            'description' => $document->description,
            'payment_purpose' => $document->payment_purpose,
            'vat_rate' => $document->vat_rate,
            'vat_amount' => $document->vat_amount,
            'amount_without_vat' => $document->amount_without_vat,
            'bank_details' => [
                'account' => $document->bank_account,
                'bik' => $document->bank_bik,
                'correspondent_account' => $document->bank_correspondent_account,
                'bank_name' => $document->bank_name,
            ],
            'source' => $document->source ? [
                'type' => $document->source_type,
                'id' => $document->source_id,
            ] : null,
            'attached_documents' => $document->attached_documents,
            'metadata' => $document->metadata,
            'notes' => $document->notes,
            'workflow' => [
                'workflow_stage' => $document->workflow_stage,
                'submitted_at' => $document->submitted_at?->toDateTimeString(),
                'approved_at' => $document->approved_at?->toDateTimeString(),
                'scheduled_at' => $document->scheduled_at?->toDateTimeString(),
                'paid_at' => $document->paid_at?->toDateTimeString(),
            ],
            'approvals_count' => $document->approvals?->count() ?? 0,
            'transactions_count' => $document->transactions?->count() ?? 0,
            'updated_at' => $document->updated_at->toDateTimeString(),
        ]);
    }
}

