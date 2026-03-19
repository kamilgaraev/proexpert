<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Core\Payments\Services\Integrations\BudgetControlService;
use App\BusinessModules\Core\Payments\Services\Export\PaymentOrderPdfService;
use App\BusinessModules\Core\Payments\Services\PaymentPurposeGenerator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\BusinessModules\Core\Payments\Http\Requests\BulkActionRequest;
use App\BusinessModules\Core\Payments\Http\Requests\StorePaymentDocumentRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Http\Responses\AdminResponse;

use function trans_message;

class PaymentDocumentController extends Controller
{
    public function __construct(
        private readonly PaymentDocumentService $service,
        private readonly BudgetControlService $budgetControl,
        private readonly PaymentOrderPdfService $pdfExport,
        private readonly PaymentPurposeGenerator $purposeGenerator
    ) {}

    /**
     * Массовые действия с документами
     */
    public function bulkAction(BulkActionRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $ids = $request->validated('ids');
            $action = $request->validated('action');
            
            $results = [
                'total_requested' => count($ids),
                'success' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            DB::transaction(function () use ($organizationId, $ids, $action, $request, &$results) {
                $documents = PaymentDocument::forOrganization($organizationId)
                    ->whereIn('id', $ids)
                    ->lockForUpdate() // Блокируем выбранные документы
                    ->get();

                foreach ($documents as $document) {
                    try {
                        switch ($action) {
                            case 'submit':
                                $this->service->submit($document);
                                break;
                            case 'approve':
                                // Для апрува может потребоваться проверка прав
                                $this->service->approve($document, $request->user()->id);
                                break;
                            case 'cancel':
                                $this->service->cancel($document, $request->validated('reason'), $request->user());
                                break;
                            case 'schedule':
                                $scheduledAt = new \DateTime($request->validated('scheduled_at'));
                                $this->service->schedule($document, $scheduledAt);
                                break;
                            case 'pay':
                                // Для оплаты берем полную сумму остатка
                                $this->service->registerPayment($document, $document->remaining_amount, [
                                    'notes' => 'Mass payment action',
                                    'created_by_user_id' => $request->user()->id
                                ]);
                                break;
                        }
                        $results['success']++;
                    } catch (\DomainException $e) {
                        $results['failed']++;
                        $results['errors'][] = "ID {$document->id}: {$e->getMessage()}";
                    } catch (\Exception $e) {
                        $results['failed']++;
                        $results['errors'][] = "ID {$document->id}: Ошибка обработки";
                        Log::error('bulk_action.item_error', [
                            'id' => $document->id,
                            'action' => $action,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            });

            $results['processed'] = $results['success'] + $results['failed'];

            return AdminResponse::success($results, trans_message('payments.documents.bulk_processed'));
        } catch (\Exception $e) {
            Log::error('payment_document.bulk_action.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.bulk_error'), 500);
        }
    }

    /**
     * Список платежных документов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            $filters = $request->validate([
                'document_type' => ['nullable', 'string'],
                'status' => ['nullable', 'string'],
                'project_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('projects', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'contract_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
                'amount_from' => ['nullable', 'numeric', 'min:0'],
                'amount_to' => ['nullable', 'numeric', 'min:0'],
                'search' => ['nullable', 'string'],
                'sort_by' => ['nullable', 'string'],
                'sort_order' => ['nullable', 'in:asc,desc'],
            ]);
            $filters['sort_by'] = $filters['sort_by'] ?? 'created_at';
            $filters['sort_order'] = $filters['sort_order'] ?? 'desc';

            $documents = $this->service->getForOrganization($organizationId, $filters);

            return AdminResponse::paginated(
                $documents->map(fn ($document) => $this->formatDocument($document)),
                [
                    'total' => $documents->count(),
                    'total_amount' => (float) $documents->sum('amount'),
                    'remaining_amount' => (float) $documents->sum('remaining_amount'),
                    'by_status' => $documents
                        ->groupBy(fn ($document) => $document->status->value)
                        ->map(fn ($items) => [
                            'count' => $items->count(),
                            'amount' => (float) $items->sum('amount'),
                        ])
                        ->toArray(),
                ]
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_document.index.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    /**
     * Получить конкретный документ
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            try {
                $document = PaymentDocument::forOrganization($organizationId)
                    ->with([
                        'project', 
                        'payerOrganization', 
                        'payeeOrganization', 
                        'payerContractor', 
                        'payeeContractor', 
                        'source', 
                        'approvals', 
                        'transactions',
                        'siteRequests',
                        'invoiceable', // Загружаем связанную сущность (Contract, Act и т.д.)
                        'estimateSplits.estimateItem.measurementUnit' // Загружаем сплиты и позиции сметы с единицами измерения
                    ])
                    ->findOrFail($id);
            } catch (\Error $e) {
                // Если ошибка при загрузке invoiceable (класс Invoice не найден)
                // Загружаем документ без eager loading invoiceable
                $document = PaymentDocument::forOrganization($organizationId)
                    ->with(['project', 'payerOrganization', 'payeeOrganization', 'payerContractor', 'payeeContractor', 'source', 'approvals', 'transactions', 'siteRequests', 'estimateSplits.estimateItem'])
                    ->where(function($query) {
                        $query->whereNull('invoiceable_type')
                              ->orWhere('invoiceable_type', '!=', 'App\\BusinessModules\\Core\\Payments\\Models\\Invoice')
                              ->orWhere('invoiceable_type', 'NOT LIKE', '%Payments\\\\Models\\\\Invoice%');
                    })
                    ->findOrFail($id);
            }

            return AdminResponse::success($this->formatDocumentDetailed($document));
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_document.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    /**
     * Создать платежный документ
     */
    public function store(StorePaymentDocumentRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) $request->user()->id;

            $validated['organization_id'] = $organizationId;
            $validated['created_by_user_id'] = $userId;

            // Маппинг contract_id в source_id и source_type, если нужно
            if (isset($validated['contract_id'])) {
                if (!isset($validated['source_id'])) {
                    $validated['source_id'] = $validated['contract_id'];
                    $validated['source_type'] = 'App\\Models\\Contract';
                }
                if (!isset($validated['invoiceable_id'])) {
                    $validated['invoiceable_id'] = $validated['contract_id'];
                    $validated['invoiceable_type'] = 'App\\Models\\Contract';
                }
            }

            // Автоматический расчет суммы для авансов по контракту
            $contractId = $validated['invoiceable_id'] 
                ?? $validated['source_id'] 
                ?? $validated['contract_id'] 
                ?? null;
            
            $isContractRelated = ($validated['invoiceable_type'] ?? null) === 'App\\Models\\Contract'
                || ($validated['source_type'] ?? null) === 'App\\Models\\Contract'
                || isset($validated['contract_id']);
            
            if (($validated['invoice_type'] ?? null) === 'advance' 
                && $isContractRelated
                && $contractId
                && (empty($validated['amount']) || $validated['amount'] == 0)) {
                
                $contract = \App\Models\Contract::where('id', $contractId)
                    ->where('organization_id', $organizationId)
                    ->first();
                
                if (!$contract) {
                    Log::warning('payment_document.store.contract_not_found', [
                        'contract_id' => $contractId,
                        'organization_id' => $organizationId,
                        'validated' => $validated,
                    ]);
                    throw new \DomainException(sprintf(
                        trans_message('payments.validation.contract_not_found_by_id'),
                        $contractId
                    ));
                }
                
                Log::info('payment_document.store.calculating_advance', [
                    'contract_id' => $contractId,
                    'planned_advance_amount' => $contract->planned_advance_amount,
                    'total_amount_with_gp' => $contract->total_amount_with_gp,
                    'total_amount' => $contract->total_amount,
                    'base_amount' => $contract->base_amount,
                ]);
                
                // Используем planned_advance_amount, если он указан
                if ($contract->planned_advance_amount && $contract->planned_advance_amount > 0) {
                    $validated['amount'] = (float) $contract->planned_advance_amount;
                } else {
                    // Для 100% аванса используем общую сумму контракта
                    // Приоритет: total_amount_with_gp (если доступен) > total_amount > base_amount
                    $totalAmount = null;
                    
                    // Для контрактов с фиксированной суммой используем total_amount_with_gp
                    if ($contract->is_fixed_amount && $contract->total_amount_with_gp !== null && $contract->total_amount_with_gp > 0) {
                        $totalAmount = (float) $contract->total_amount_with_gp;
                    }
                    // Если total_amount_with_gp недоступен или контракт с нефиксированной суммой
                    elseif ($contract->total_amount && $contract->total_amount > 0) {
                        $totalAmount = (float) $contract->total_amount;
                    } 
                    // Используем base_amount как последний вариант
                    elseif ($contract->base_amount && $contract->base_amount > 0) {
                        $totalAmount = (float) $contract->base_amount;
                    }
                    
                    if ($totalAmount && $totalAmount > 0) {
                        $validated['amount'] = $totalAmount;
                    } else {
                        // Если сумма не может быть определена автоматически, 
                        // но пользователь не указал сумму - требуем указать её вручную
                        Log::warning('payment_document.store.cannot_calculate_advance', [
                            'contract_id' => $contractId,
                            'contract_data' => [
                                'planned_advance_amount' => $contract->planned_advance_amount,
                                'total_amount_with_gp' => $contract->total_amount_with_gp,
                                'total_amount' => $contract->total_amount,
                                'base_amount' => $contract->base_amount,
                                'is_fixed_amount' => $contract->is_fixed_amount,
                            ],
                        ]);
                        
                        // Если сумма не указана и не может быть рассчитана - требуем указать вручную
                        $contractNumber = $contract->number ?? $contractId;
                        throw new \DomainException(sprintf(
                            trans_message('payments.validation.advance_amount_auto_detect_failed'),
                            $contractNumber
                        ));
                    }
                }
                
                Log::info('payment_document.store.advance_calculated', [
                    'contract_id' => $contractId,
                    'calculated_amount' => $validated['amount'],
                ]);
            }

            $warnings = [];

            // Проверка отклонений цен перед созданием
            if (!empty($validated['estimate_splits'])) {
                $deviationAnalysis = $this->service->analyzePriceDeviation($validated['estimate_splits']);
                
                if ($deviationAnalysis['is_blocked'] && empty($validated['overprice_justification'])) {
                    return AdminResponse::error(trans_message('payments.documents.deviation_justification_required'), 422, [
                        'requires_justification' => true,
                        'deviation_data' => $deviationAnalysis,
                    ]);
                }

                if ($deviationAnalysis['requires_approval']) {
                    $warnings[] = trans_message('payments.documents.deviation_warning');
                }
            }

            // Сервис сам обработает estimate_splits внутри метода create()
            $document = $this->service->create($validated);

            // Если было обоснование - сохраняем его в notes
            if (!empty($validated['overprice_justification'])) {
                $document->notes = trim(($document->notes ?? '') . "\n\nОбоснование переплаты:\n" . $validated['overprice_justification']);
                $document->saveQuietly();
            }

            // Загружаем splits для ответа
            $document->load('estimateSplits.estimateItem');

            return AdminResponse::success(
                array_merge($this->formatDocumentDetailed($document), [
                    'warnings' => $warnings,
                ]),
                trans_message('payments.documents.created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\DomainException | \InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('payment_document.store.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.create_error'), 500);
        }
    }

    /**
     * Обновить платежный документ
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $validated = $request->validate([
                'document_date' => 'sometimes|date',
                'due_date' => 'sometimes|date',
                'project_id' => [
                    'sometimes',
                    'nullable',
                    'integer',
                    Rule::exists('projects', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
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

            return AdminResponse::success(
                $this->formatDocumentDetailed($updated),
                trans_message('payments.documents.updated')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_document.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.update_error'), 500);
        }
    }

    /**
     * Отправить на утверждение
     */
    public function submit(Request $request, int|string $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            // Бюджетный контроль перед отправкой
            $this->budgetControl->validateForApproval($document);

            $submitted = $this->service->submit($document);

            return AdminResponse::success(
                $this->formatDocumentDetailed($submitted),
                trans_message('payments.documents.submitted')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_document.submit.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.submit_error'), 500);
        }
    }

    /**
     * Сгенерировать печатную форму платежного поручения
     */
    public function printOrder(Request $request, int|string $id): \Illuminate\Http\Response
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)
                ->with(['payerOrganization', 'payeeOrganization', 'payerContractor', 'payeeContractor'])
                ->findOrFail($id);

            $pdfContent = $this->pdfExport->generate($document);

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="payment_order_' . $document->document_number . '.pdf"',
            ]);
        } catch (\Exception $e) {
            Log::error('payment_document.print_order.error', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->view('errors.500', [], 500);
        }
    }

    /**
     * Сгенерировать назначение платежа
     */
    public function generatePurpose(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'document_type' => 'required|string',
                'data' => 'required|array',
            ]);

            $type = PaymentDocumentType::from($validated['document_type']);
            $purpose = $this->purposeGenerator->generate($type, $validated['data']);

            return AdminResponse::success([
                'purpose' => $purpose,
            ], trans_message('payments.documents.purpose_generated'));
        } catch (\Exception $e) {
            Log::error('payment_document.generate_purpose.error', [
                'document_type' => $validated['document_type'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error($e->getMessage() ?: trans_message('payments.documents.purpose_error'), 400);
        }
    }

    /**
     * Запланировать платеж
     */
    public function schedule(Request $request, int|string $id): JsonResponse
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

            return AdminResponse::success(
                $this->formatDocumentDetailed($scheduled),
                trans_message('payments.documents.scheduled')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_document.schedule.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.schedule_error'), 500);
        }
    }

    /**
     * Зарегистрировать платеж
     */
    public function registerPayment(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'nullable|string',
                'reference_number' => 'nullable|string',
                'bank_transaction_id' => 'nullable|string',
                'transaction_date' => 'nullable|date',
                'payment_date' => 'nullable|date',
                'notes' => 'nullable|string',
                'metadata' => 'nullable|array',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->user()->id;

            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            // Поддержка обоих полей: payment_date и transaction_date
            if (!isset($validated['transaction_date']) && isset($validated['payment_date'])) {
                $validated['transaction_date'] = $validated['payment_date'];
            }

            $validated['created_by_user_id'] = $userId;

            $paid = $this->service->registerPayment($document, $validated['amount'], $validated);

            return AdminResponse::success(
                $this->formatDocumentDetailed($paid),
                trans_message('payments.documents.registered')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_document.register_payment.error', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'request_data' => $request->all(),
                'timestamp' => now()->toIso8601String(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.register_error'), 500, config('app.debug') ? [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] : null);
        }
    }

    /**
     * Отменить документ
     */
    public function cancel(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => 'required|string|min:3',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $cancelled = $this->service->cancel($document, $validated['reason'], $request->user());

            return AdminResponse::success(
                $this->formatDocumentDetailed($cancelled),
                trans_message('payments.documents.cancelled')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_document.cancel.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.cancel_error'), 500);
        }
    }

    /**
     * Удалить документ
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);

            $this->service->delete($document);

            return AdminResponse::success(null, trans_message('payments.documents.deleted'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payment_document.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.delete_error'), 500);
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

            return AdminResponse::paginated(
                $documents->map(fn($doc) => $this->formatDocument($doc)),
                [
                    'total' => $documents->count(),
                    'total_amount' => $documents->sum('remaining_amount'),
                ]
            );
        } catch (\Exception $e) {
            Log::error('payment_document.overdue.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
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

            return AdminResponse::paginated(
                $documents->map(fn($doc) => $this->formatDocument($doc)),
                [
                    'total' => $documents->count(),
                    'total_amount' => $documents->sum('remaining_amount'),
                    'days' => $days,
                ]
            );
        } catch (\Exception $e) {
            Log::error('payment_document.upcoming.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
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

            return AdminResponse::success($stats);
        } catch (\Exception $e) {
            Log::error('payment_document.statistics.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    /**
     * Форматирование документа (краткий формат)
     */
    private function formatDocument(PaymentDocument $document): array
    {
        // Владелец организации может отменять документы в любом статусе
        $canBeCancelled = $document->canBeCancelled();
        $user = request()->user();
        if ($user && !$canBeCancelled) {
            // Если по статусу нельзя отменить, но пользователь владелец - разрешаем
            $canBeCancelled = $user->isOrganizationOwner($document->organization_id);
        }

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
            'site_requests_count' => (int) ($document->site_requests_count ?? 0),
            'problem_flags' => $this->buildProblemFlags($document),
            'workflow_summary' => $this->buildWorkflowSummary($document, $this->buildProblemFlags($document), []),
            'can_be_cancelled' => $canBeCancelled,
            'created_at' => $document->created_at->toDateTimeString(),
        ];
    }

    /**
     * Форматирование документа (детальный формат)
     */
    private function formatDocumentDetailed(PaymentDocument $document): array
    {
        $basic = $this->formatDocument($document);
        
        // Проверка прав на утверждение
        $user = request()->user();
        $canApprove = false;
        
        // Разрешаем утверждение для статусов submitted и pending_approval
        if (in_array($document->status->value, ['submitted', 'pending_approval']) && $user) {
            // 1. Проверка прямой записи на утверждение
            $hasApprovalRequest = $document->approvals()
                ->where('approver_user_id', $user->id)
                ->where('status', 'pending')
                ->exists();
                
            // 2. Проверка прав администратора/владельца (GOD MODE)
            $isSuperUser = false;
            
            $orgId = $document->organization_id;
            $context = ['organization_id' => $orgId];
            
            // Логирование для отладки
            Log::info('DEBUG_AUTH: Checking rights for doc ' . $document->id, [
                'user_id' => $user->id,
                'org_id' => $orgId,
                'is_system_admin' => $user->isSystemAdmin(),
                'is_org_owner' => $user->isOrganizationOwner($orgId),
            ]);

            // Проверка через нативные методы модели User
            if ($user->isSystemAdmin() || $user->isOrganizationOwner($orgId)) {
                $isSuperUser = true;
            } 
            // Проверяем роль администратора через новую систему
            elseif ($user->hasRole('admin', null) || $user->hasRole('finance_admin', null)) {
                $isSuperUser = true;
            }
            
            // Если все еще нет прав, проверяем конкретное разрешение С ЯВНЫМ КОНТЕКСТОМ
            if (!$isSuperUser) {
                $canApprovePermission = $user->can('payments.transaction.approve', $context);
                
                Log::info('DEBUG_AUTH: Checking permission payments.transaction.approve', [
                    'result' => $canApprovePermission,
                    'context' => $context
                ]);
                
                if ($canApprovePermission) {
                    $isSuperUser = true;
                }
            }
                          
            $canApprove = $hasApprovalRequest || $isSuperUser;
        }

        // Определяем contract_id из invoiceable
        $contractId = null;
        if ($document->invoiceable_type === 'App\\Models\\Contract' && $document->invoiceable_id) {
            $contractId = $document->invoiceable_id;
        }
        
        // Форматируем invoiceable для ответа
        $invoiceable = null;
        
        // Пытаемся загрузить invoiceable, если есть тип и ID
        if ($document->invoiceable_type && $document->invoiceable_id) {
            try {
                // Проверяем, не является ли это старым классом Invoice
                if (!str_contains($document->invoiceable_type, 'Payments\\Models\\Invoice')) {
                    $invoiceableModel = $document->invoiceable;
                    
                    if ($invoiceableModel) {
                        $invoiceable = [
                            'type' => $document->invoiceable_type,
                            'id' => $document->invoiceable_id,
                        ];
                        
                        // Если это Contract, добавляем дополнительную информацию
                        if ($document->invoiceable_type === 'App\\Models\\Contract') {
                            $invoiceable['number'] = $invoiceableModel->number ?? null;
                            $invoiceable['subject'] = $invoiceableModel->subject ?? null;
                            $contractId = $document->invoiceable_id;
                        }
                        
                        // Если это Act, добавляем информацию об акте
                        if ($document->invoiceable_type === 'App\\Models\\ContractPerformanceAct') {
                            $invoiceable['number'] = $invoiceableModel->number ?? null;
                            $invoiceable['act_date'] = $invoiceableModel->act_date?->format('Y-m-d') ?? null;
                            // Если акт связан с контрактом, получаем contract_id из акта
                            if ($invoiceableModel->contract_id) {
                                $contractId = $invoiceableModel->contract_id;
                            }
                        }
                    }
                }
            } catch (\Error | \Exception $e) {
                // Класс не найден или ошибка загрузки - оставляем null
                Log::debug('payment_document.invoiceable_load_failed', [
                    'document_id' => $document->id,
                    'invoiceable_type' => $document->invoiceable_type,
                    'invoiceable_id' => $document->invoiceable_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        

        $relatedSiteRequests = $document->relationLoaded('siteRequests')
            ? $document->siteRequests->map(fn($siteRequest) => [
                'id' => $siteRequest->id,
                'title' => $siteRequest->title,
                'status' => is_object($siteRequest->status) ? $siteRequest->status->value : $siteRequest->status,
                'project_id' => $siteRequest->project_id,
                'payment_amount' => $siteRequest->pivot?->amount,
            ])->values()->all()
            : [];
        $problemFlags = $this->buildProblemFlags($document);
        $workflowSummary = $this->buildWorkflowSummary($document, $problemFlags, $relatedSiteRequests);

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
            'contract_id' => $contractId, // Прямая ссылка на контракт (если есть)
            'invoiceable' => $invoiceable, // Polymorphic связь с источником (Contract, Act и т.д.)
            'source' => $document->source ? [
                'type' => $document->source_type,
                'id' => $document->source_id,
            ] : null,
            'attached_documents' => $document->attached_documents,
            'metadata' => $document->metadata,
            'notes' => $document->notes,
            'estimate_splits' => $document->relationLoaded('estimateSplits') ? $document->estimateSplits->map(fn($split) => [
                'id' => $split->id,
                'estimate_item_id' => $split->estimate_item_id,
                'quantity' => (float)$split->quantity,
                'unit_price_plan' => (float)$split->unit_price_plan,
                'unit_price_actual' => (float)$split->unit_price_actual,
                'amount' => (float)$split->amount,
                'percentage' => (float)$split->percentage,
                'price_deviation' => (float)$split->price_deviation,
                'estimate_item' => $split->estimateItem ? [
                    'id' => $split->estimateItem->id,
                    'title' => $split->estimateItem->name,
                    'unit_name' => $split->estimateItem->measurementUnit ? $split->estimateItem->measurementUnit->short_name : null,
                ] : null,
            ]) : [],
            'can_be_approved_by_current_user' => $canApprove, // Флаг для фронтенда
            // Информация о получателе (только если зарегистрирован)
            'recipient_is_registered' => $document->hasRegisteredRecipient(),
            'recipient_organization_id' => $document->recipient_organization_id,
            'recipient_viewed_at' => $document->recipient_viewed_at?->toDateTimeString(),
            'recipient_confirmed_at' => $document->recipient_confirmed_at?->toDateTimeString(),
            'recipient_confirmation_comment' => $document->recipient_confirmation_comment,
            'recipient_confirmed_by' => $document->recipientConfirmedBy ? [
                'id' => $document->recipientConfirmedBy->id,
                'name' => $document->recipientConfirmedBy->name,
            ] : null,
            'workflow' => [
                'workflow_stage' => $document->workflow_stage,
                'submitted_at' => $document->submitted_at?->toDateTimeString(),
                'approved_at' => $document->approved_at?->toDateTimeString(),
                'scheduled_at' => $document->scheduled_at?->toDateTimeString(),
                'paid_at' => $document->paid_at?->toDateTimeString(),
            ],
            'workflow_summary' => $workflowSummary,
            'problem_flags' => $problemFlags,
            'related_site_requests' => $relatedSiteRequests,
            'approvals_count' => $document->approvals?->count() ?? 0,
            'transactions_count' => $document->transactions?->count() ?? 0,
            'updated_at' => $document->updated_at->toDateTimeString(),
        ]);
    }

    private function buildProblemFlags(PaymentDocument $document): array
    {
        $flags = [];

        if ($document->document_type->isOutgoing() && (!$document->bank_account || !$document->bank_bik)) {
            $flags[] = 'missing_bank_details';
        }

        if (!$document->payee_contractor_id && !$document->payee_organization_id) {
            $flags[] = 'missing_counterparty';
        }

        if ($document->status === PaymentDocumentStatus::DRAFT) {
            $flags[] = 'awaiting_submission';
        }

        if (in_array($document->status, [PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL], true)) {
            $flags[] = 'awaiting_approval';
        }

        if ($document->status === PaymentDocumentStatus::APPROVED) {
            $flags[] = 'awaiting_schedule';
        }

        if (in_array($document->status, [PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::SCHEDULED], true)
            && (float) $document->remaining_amount > 0) {
            $flags[] = 'awaiting_payment';
        }

        if ($document->status === PaymentDocumentStatus::PARTIALLY_PAID) {
            $flags[] = 'partially_paid';
        }

        if ($document->isOverdue()) {
            $flags[] = 'overdue';
        }

        if ($document->relationLoaded('estimateSplits') && $document->estimateSplits->contains(fn($split) => (float) $split->price_deviation > 0)) {
            $flags[] = 'has_estimate_deviation';
        }

        return array_values(array_unique($flags));
    }

    private function buildWorkflowSummary(PaymentDocument $document, array $problemFlags, array $relatedSiteRequests): array
    {
        $currentStage = match ($document->status) {
            PaymentDocumentStatus::DRAFT => 'draft',
            PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL => 'approval',
            PaymentDocumentStatus::APPROVED => 'approved',
            PaymentDocumentStatus::SCHEDULED => 'scheduled',
            PaymentDocumentStatus::PARTIALLY_PAID => 'partial_payment',
            PaymentDocumentStatus::PAID => 'paid',
            PaymentDocumentStatus::REJECTED => 'rejected',
            PaymentDocumentStatus::CANCELLED => 'cancelled',
            default => 'unknown',
        };

        $nextAction = match ($document->status) {
            PaymentDocumentStatus::DRAFT => 'submit',
            PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL => 'approve_or_reject',
            PaymentDocumentStatus::APPROVED => 'schedule_payment',
            PaymentDocumentStatus::SCHEDULED => 'register_payment',
            PaymentDocumentStatus::PARTIALLY_PAID => 'complete_payment',
            default => null,
        };

        return [
            'current_stage' => $currentStage,
            'next_action' => $nextAction,
            'is_blocked' => !empty($problemFlags),
            'blockers' => $problemFlags,
            'related_site_requests' => $relatedSiteRequests,
        ];
    }
}
