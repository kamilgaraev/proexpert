<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentCreated;
use App\BusinessModules\Core\Payments\Events\PaymentRequestReceived;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentDocumentService
{
    public function __construct(
        private readonly PaymentDocumentStateMachine $stateMachine,
        private readonly ApprovalWorkflowService $approvalWorkflow,
        private readonly PaymentValidationService $validator
    ) {}

    /**
     * Создать платежный документ
     */
    public function create(array $data): PaymentDocument
    {
        $attempts = 0;
        $maxAttempts = 3;
        $wasNumberProvided = isset($data['document_number']);

        while ($attempts < $maxAttempts) {
            DB::beginTransaction();

            try {
                // Валидация
                $this->validator->validate($data);

                // Генерация номера документа
                if (!isset($data['document_number'])) {
                    $data['document_number'] = $this->generateDocumentNumber(
                        $data['organization_id'],
                        PaymentDocumentType::from($data['document_type'])
                    );
                }

                // Расчет сумм с НДС
                $data = $this->calculateAmounts($data);

                // Создание документа
                $document = PaymentDocument::create($data);

                // Автоматически определяем и кэшируем получателя-организацию
                $this->detectAndSetRecipientOrganization($document);

                Log::info('payment_document.created', [
                    'document_id' => $document->id,
                    'document_number' => $document->document_number,
                    'document_type' => $document->document_type->value,
                    'amount' => $document->amount,
                    'recipient_org_id' => $document->recipient_organization_id,
                ]);

                // Генерируем событие
                event(new PaymentDocumentCreated($document));
                
                // Для платежных требований - дополнительное событие
                if ($document->document_type === PaymentDocumentType::PAYMENT_REQUEST && $document->payee_contractor_id) {
                    event(new PaymentRequestReceived($document, $document->payee_contractor_id));
                }

                DB::commit();
                return $document;

            } catch (QueryException $e) {
                DB::rollBack();

                // SQLSTATE 23505: Unique violation
                // Проверяем, что ошибка связана именно с номером документа
                // Поддержка старого и нового имени ограничения
                $isUniqueViolation = $e->getCode() == '23505';
                $isDocumentNumberConstraint = str_contains($e->getMessage(), 'payment_documents_document_number_unique') || 
                                            str_contains($e->getMessage(), 'payment_documents_org_id_doc_num_unique');

                if ($isUniqueViolation && $isDocumentNumberConstraint) {
                    if (!$wasNumberProvided) {
                        $attempts++;
                        unset($data['document_number']); // Сброс номера для новой генерации
                        
                        if ($attempts < $maxAttempts) {
                            // Небольшая задержка перед повтором (100ms, 200ms)
                            usleep(100000 * $attempts); 
                            continue;
                        }
                    }
                }
                
                Log::error('payment_document.create_failed', [
                    'data' => $data,
                    'error' => $e->getMessage(),
                ]);

                throw $e;

            } catch (\Exception $e) {
                DB::rollBack();
                
                Log::error('payment_document.create_failed', [
                    'data' => $data,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        throw new \Exception("Не удалось создать документ после {$maxAttempts} попыток. Пожалуйста, попробуйте еще раз.");
    }

    /**
     * Обновить платежный документ
     */
    public function update(PaymentDocument $document, array $data): PaymentDocument
    {
        if (!$document->canBeEdited()) {
            throw new \DomainException('Документ нельзя редактировать в текущем статусе');
        }

        DB::beginTransaction();

        try {
            // Валидация изменений
            $this->validator->validate(array_merge($document->toArray(), $data), $document);

            // Перерасчет сумм если изменилась amount или vat_rate
            if (isset($data['amount']) || isset($data['vat_rate'])) {
                $data = $this->calculateAmounts($data, $document);
            }

            $document->update($data);

            Log::info('payment_document.updated', [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
                'changes' => array_keys($data),
            ]);

            DB::commit();
            return $document->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_document.update_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Отправить документ на утверждение
     */
    public function submit(PaymentDocument $document): PaymentDocument
    {
        DB::beginTransaction();

        try {
            // Финальная валидация перед отправкой
            $this->validator->validateBeforeSubmission($document);

            // Шаг 1: Переводим в статус "submitted" (отправлен на рассмотрение)
            $this->stateMachine->submit($document);

            // Шаг 2: Инициируем процесс утверждения (submitted → pending_approval)
            $this->approvalWorkflow->initiateApproval($document);

            Log::info('payment_document.submitted', [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
            ]);

            DB::commit();
            return $document->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_document.submit_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Утвердить документ
     */
    public function approve(PaymentDocument $document, ?int $approvedByUserId = null): PaymentDocument
    {
        DB::beginTransaction();

        try {
            // Утверждаем документ через state machine
            $this->stateMachine->approve($document, $approvedByUserId);

            Log::info('payment_document.approved', [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
                'approved_by_user_id' => $approvedByUserId,
            ]);

            DB::commit();
            return $document->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_document.approve_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Запланировать платеж
     */
    public function schedule(PaymentDocument $document, ?\DateTime $scheduledAt = null): PaymentDocument
    {
        if ($document->status !== PaymentDocumentStatus::APPROVED) {
            throw new \DomainException('Можно планировать только утвержденные документы');
        }

        $this->stateMachine->schedule($document, $scheduledAt);

        Log::info('payment_document.scheduled', [
            'document_id' => $document->id,
            'scheduled_at' => $scheduledAt?->format('Y-m-d H:i:s'),
        ]);

        return $document->fresh();
    }

    /**
     * Зарегистрировать платеж
     */
    public function registerPayment(PaymentDocument $document, float $amount, array $paymentData): PaymentDocument
    {
        Log::info('payment_document.register_payment.started', [
            'document_id' => $document->id,
            'amount' => $amount,
            'payment_data' => $paymentData,
        ]);

        if (!$document->canBePaid()) {
            Log::warning('payment_document.register_payment.cannot_be_paid', [
                'document_id' => $document->id,
                'status' => $document->status->value,
                'remaining_amount' => $document->remaining_amount,
            ]);
            throw new \DomainException('Документ нельзя оплатить в текущем статусе');
        }

        DB::beginTransaction();

        try {
            Log::info('payment_document.register_payment.transaction_started', [
                'document_id' => $document->id,
            ]);

            // Проверка суммы
            if ($amount > $document->remaining_amount) {
                Log::warning('payment_document.register_payment.amount_exceeds', [
                    'document_id' => $document->id,
                    'amount' => $amount,
                    'remaining_amount' => $document->remaining_amount,
                ]);
                throw new \DomainException('Сумма платежа превышает остаток к оплате');
            }

            Log::info('payment_document.register_payment.preparing_data', [
                'document_id' => $document->id,
            ]);

            // Подготовка данных для вставки транзакции
            $transactionData = [
                'payment_document_id' => $document->id,
                'organization_id' => $document->organization_id,
                'project_id' => $document->project_id,
                'payer_organization_id' => $document->payer_organization_id,
                'payee_organization_id' => $document->payee_organization_id,
                'payer_contractor_id' => $document->payer_contractor_id,
                'payee_contractor_id' => $document->payee_contractor_id,
                'amount' => $amount,
                'currency' => $document->currency,
                'payment_method' => $paymentData['payment_method'] ?? 'bank_transfer',
                'reference_number' => $paymentData['reference_number'] ?? null,
                'bank_transaction_id' => $paymentData['bank_transaction_id'] ?? null,
                'transaction_date' => $paymentData['transaction_date'] ?? now(),
                'value_date' => $paymentData['value_date'] ?? now(),
                'status' => 'completed',
                'notes' => $paymentData['notes'] ?? null,
                'metadata' => json_encode($paymentData['metadata'] ?? []),
                'created_by_user_id' => $paymentData['created_by_user_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            Log::info('payment_document.register_payment.checking_invoice_column', [
                'document_id' => $document->id,
            ]);

            // Если колонка invoice_id еще существует (до выполнения миграции удаления), добавляем null
            if (\Schema::hasColumn('payment_transactions', 'invoice_id')) {
                $transactionData['invoice_id'] = null;
                Log::info('payment_document.register_payment.invoice_id_added', [
                    'document_id' => $document->id,
                ]);
            }

            Log::info('payment_document.register_payment.inserting_transaction', [
                'document_id' => $document->id,
                'transaction_data_keys' => array_keys($transactionData),
            ]);

            // Создаем транзакцию платежа
            $transaction = DB::table('payment_transactions')->insertGetId($transactionData);
            
            Log::info('payment_document.register_payment.transaction_inserted', [
                'document_id' => $document->id,
                'transaction_id' => $transaction,
            ]);

            Log::info('payment_document.register_payment.updating_amounts', [
                'document_id' => $document->id,
            ]);

            // Обновляем суммы в документе
            $newPaidAmount = $document->paid_amount + $amount;
            $document->paid_amount = $newPaidAmount;
            $document->remaining_amount = $document->amount - $newPaidAmount;
            $document->save();

            Log::info('payment_document.register_payment.amounts_updated', [
                'document_id' => $document->id,
                'new_paid_amount' => $newPaidAmount,
                'remaining_amount' => $document->remaining_amount,
            ]);

            // Загружаем транзакцию как модель для уведомлений
            $transactionModel = PaymentTransaction::find($transaction);
            
            Log::info('payment_document.register_payment.transaction_loaded', [
                'document_id' => $document->id,
                'transaction_model_exists' => $transactionModel !== null,
            ]);

            // Отправляем уведомление получателю (если зарегистрирован)
            if ($transactionModel) {
                try {
                    Log::info('payment_document.register_payment.sending_notification', [
                        'document_id' => $document->id,
                    ]);
                    $notificationService = app(\App\BusinessModules\Core\Payments\Services\PaymentRecipientNotificationService::class);
                    $notificationService->notifyRecipientAboutPayment($document, $transactionModel);
                } catch (\Exception $e) {
                    // Не бросаем исключение - отсутствие уведомления не должно ломать регистрацию платежа
                    Log::warning('payment_document.notify_recipient_failed', [
                        'document_id' => $document->id,
                        'transaction_id' => $transaction,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::info('payment_document.register_payment.updating_status', [
                'document_id' => $document->id,
                'remaining_amount' => $document->remaining_amount,
            ]);

            // Определяем новый статус
            if ($document->remaining_amount <= 0.01) { // учитываем погрешность
                Log::info('payment_document.register_payment.marking_paid', [
                    'document_id' => $document->id,
                ]);
                // Передаем transactionId в событие через временное свойство
                $document->setAttribute('_last_transaction_id', $transaction);
                $this->stateMachine->markPaid($document, $newPaidAmount);
            } else {
                Log::info('payment_document.register_payment.marking_partially_paid', [
                    'document_id' => $document->id,
                ]);
                // Для частичной оплаты тоже нужно событие с transactionId
                $document->setAttribute('_last_transaction_id', $transaction);
                $this->stateMachine->markPartiallyPaid($document, $amount);
            }

            Log::info('payment_document.payment_registered', [
                'document_id' => $document->id,
                'transaction_id' => $transaction,
                'amount' => $amount,
                'remaining' => $document->remaining_amount,
            ]);

            Log::info('payment_document.register_payment.committing', [
                'document_id' => $document->id,
            ]);

            DB::commit();
            
            Log::info('payment_document.register_payment.committed', [
                'document_id' => $document->id,
            ]);

            return $document->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_document.payment_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            throw $e;
        }
    }

    /**
     * Отменить документ
     */
    public function cancel(PaymentDocument $document, string $reason, ?\App\Models\User $user = null): PaymentDocument
    {
        // Владелец организации может отменять платежи в любом статусе (GOD MODE)
        $isOrganizationOwner = false;
        if ($user) {
            $isOrganizationOwner = $user->isOrganizationOwner($document->organization_id);
        }

        if (!$isOrganizationOwner && !$document->canBeCancelled()) {
            throw new \DomainException('Документ нельзя отменить в текущем статусе');
        }

        $this->stateMachine->cancel($document, $reason);

        Log::info('payment_document.cancelled', [
            'document_id' => $document->id,
            'reason' => $reason,
            'cancelled_by_owner' => $isOrganizationOwner,
            'user_id' => $user?->id,
        ]);

        return $document->fresh();
    }

    /**
     * Удалить документ (soft delete)
     */
    public function delete(PaymentDocument $document): bool
    {
        // Проверяем, что документ не оплачен
        if ($document->status === PaymentDocumentStatus::PAID) {
            throw new \DomainException('Нельзя удалить оплаченный документ');
        }

        // Проверяем, что нет транзакций
        if ($document->transactions()->count() > 0) {
            throw new \DomainException('Нельзя удалить документ с транзакциями');
        }

        $documentNumber = $document->document_number;
        $document->delete();

        Log::info('payment_document.deleted', [
            'document_id' => $document->id,
            'document_number' => $documentNumber,
        ]);

        return true;
    }

    /**
     * Получить документы для организации
     */
    public function getForOrganization(int $organizationId, array $filters = []): Collection
    {
        $query = PaymentDocument::forOrganization($organizationId)
            ->with(['project', 'payerOrganization', 'payeeOrganization', 'payerContractor', 'payeeContractor']);

        // Применяем фильтры
        if (isset($filters['document_type'])) {
            $query->byType(PaymentDocumentType::from($filters['document_type']));
        }

        if (isset($filters['status'])) {
            $query->byStatus(PaymentDocumentStatus::from($filters['status']));
        }

        if (isset($filters['project_id'])) {
            $query->forProject($filters['project_id']);
        }

        if (isset($filters['contract_id'])) {
            $contractId = $filters['contract_id'];
            // Ищем документы, связанные с контрактом напрямую или через акт
            $query->where(function($q) use ($contractId) {
                // Прямая связь с контрактом
                $q->where(function($subQ) use ($contractId) {
                    $subQ->where('invoiceable_type', 'App\\Models\\Contract')
                         ->where('invoiceable_id', $contractId);
                })
                // Или связь через акт этого контракта
                ->orWhere(function($subQ) use ($contractId) {
                    $subQ->where('invoiceable_type', 'App\\Models\\ContractPerformanceAct')
                         ->whereExists(function($existsQuery) use ($contractId) {
                             $existsQuery->select(DB::raw(1))
                                 ->from('contract_performance_acts')
                                 ->whereColumn('contract_performance_acts.id', 'payment_documents.invoiceable_id')
                                 ->where('contract_performance_acts.contract_id', $contractId);
                         });
                });
            });
        }

        if (isset($filters['date_from'])) {
            $query->where('document_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('document_date', '<=', $filters['date_to']);
        }

        if (isset($filters['amount_from'])) {
            $query->where('amount', '>=', $filters['amount_from']);
        }

        if (isset($filters['amount_to'])) {
            $query->where('amount', '<=', $filters['amount_to']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('payment_purpose', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->get();
    }

    /**
     * Получить просроченные документы
     */
    public function getOverdue(int $organizationId): Collection
    {
        return PaymentDocument::forOrganization($organizationId)
            ->overdue()
            ->with(['project', 'payeeContractor'])
            ->orderBy('due_date', 'asc')
            ->get();
    }

    /**
     * Получить предстоящие платежи
     */
    public function getUpcoming(int $organizationId, int $days = 7): Collection
    {
        return PaymentDocument::forOrganization($organizationId)
            ->upcoming($days)
            ->with(['project', 'payeeContractor'])
            ->orderBy('due_date', 'asc')
            ->get();
    }

    /**
     * Создать платежное требование (от подрядчика)
     */
    public function createPaymentRequest(array $data): PaymentDocument
    {
        $data['document_type'] = PaymentDocumentType::PAYMENT_REQUEST->value;
        $data['status'] = PaymentDocumentStatus::DRAFT->value;

        return $this->create($data);
    }

    /**
     * Создать счет на оплату (клиенту)
     */
    public function createInvoice(array $data): PaymentDocument
    {
        $data['document_type'] = PaymentDocumentType::INVOICE->value;
        $data['status'] = PaymentDocumentStatus::DRAFT->value;

        return $this->create($data);
    }

    /**
     * Создать платежное поручение
     */
    public function createPaymentOrder(array $data): PaymentDocument
    {
        $data['document_type'] = PaymentDocumentType::PAYMENT_ORDER->value;
        $data['status'] = PaymentDocumentStatus::DRAFT->value;

        return $this->create($data);
    }

    /**
     * Генерировать номер документа
     */
    private function generateDocumentNumber(int $organizationId, PaymentDocumentType $type): string
    {
        $year = date('Y');
        $month = date('m');
        $docType = $type->value;

        // Используем PostgreSQL функцию для генерации уникального номера (thread-safe)
        $documentNumber = DB::selectOne(
            'SELECT get_next_payment_document_number(?, ?, ?, ?) as number',
            [$organizationId, $docType, $year, $month]
        )->number;

        Log::info('payment_document.number_generated', [
            'organization_id' => $organizationId,
            'document_type' => $docType,
            'document_number' => $documentNumber,
        ]);

        return $documentNumber;
    }

    /**
     * Рассчитать суммы с НДС
     */
    private function calculateAmounts(array $data, ?PaymentDocument $existingDocument = null): array
    {
        $amount = $data['amount'] ?? $existingDocument?->amount ?? 0;
        $vatRate = $data['vat_rate'] ?? $existingDocument?->vat_rate ?? 20;

        // Сумма содержит НДС
        $amountWithoutVat = $amount / (1 + $vatRate / 100);
        $vatAmount = $amount - $amountWithoutVat;

        $data['amount_without_vat'] = round($amountWithoutVat, 2);
        $data['vat_amount'] = round($vatAmount, 2);
        $data['remaining_amount'] = $amount - ($data['paid_amount'] ?? $existingDocument?->paid_amount ?? 0);

        return $data;
    }

    /**
     * Получить статистику по документам
     */
    public function getStatistics(int $organizationId): array
    {
        $documents = PaymentDocument::forOrganization($organizationId)->get();

        return [
            'total_count' => $documents->count(),
            'total_amount' => $documents->sum('amount'),
            'paid_amount' => $documents->sum('paid_amount'),
            'remaining_amount' => $documents->sum('remaining_amount'),
            'by_status' => [
                'draft' => $documents->where('status', PaymentDocumentStatus::DRAFT)->count(),
                'pending_approval' => $documents->where('status', PaymentDocumentStatus::PENDING_APPROVAL)->count(),
                'approved' => $documents->where('status', PaymentDocumentStatus::APPROVED)->count(),
                'scheduled' => $documents->where('status', PaymentDocumentStatus::SCHEDULED)->count(),
                'paid' => $documents->where('status', PaymentDocumentStatus::PAID)->count(),
                'partially_paid' => $documents->where('status', PaymentDocumentStatus::PARTIALLY_PAID)->count(),
                'rejected' => $documents->where('status', PaymentDocumentStatus::REJECTED)->count(),
                'cancelled' => $documents->where('status', PaymentDocumentStatus::CANCELLED)->count(),
            ],
            'by_type' => [
                'payment_request' => $documents->where('document_type', PaymentDocumentType::PAYMENT_REQUEST)->count(),
                'invoice' => $documents->where('document_type', PaymentDocumentType::INVOICE)->count(),
                'payment_order' => $documents->where('document_type', PaymentDocumentType::PAYMENT_ORDER)->count(),
                'incoming_payment' => $documents->where('document_type', PaymentDocumentType::INCOMING_PAYMENT)->count(),
                'expense' => $documents->where('document_type', PaymentDocumentType::EXPENSE)->count(),
                'offset_act' => $documents->where('document_type', PaymentDocumentType::OFFSET_ACT)->count(),
            ],
            'overdue_count' => $documents->filter(fn($d) => $d->isOverdue())->count(),
            'overdue_amount' => $documents->filter(fn($d) => $d->isOverdue())->sum('remaining_amount'),
        ];
    }

    /**
     * Отметить документ как оплаченный
     */
    public function markAsPaid(PaymentDocument $document, PaymentTransaction $transaction): void
    {
        $document->update([
            'status' => PaymentDocumentStatus::PAID,
            'paid_at' => $transaction->transaction_date ?? now(),
        ]);

        Log::info('payment_document.marked_as_paid', [
            'document_id' => $document->id,
            'transaction_id' => $transaction->id,
        ]);
    }

    /**
     * Обновить статус документа на основе оплаченной суммы
     */
    public function updateStatus(PaymentDocument $document): void
    {
        $oldStatus = $document->status;
        
        // Определяем новый статус на основе оплаченной суммы
        if ($document->remaining_amount <= 0) {
            $newStatus = PaymentDocumentStatus::PAID;
        } elseif ($document->paid_amount > 0) {
            $newStatus = PaymentDocumentStatus::PARTIALLY_PAID;
        } elseif ($document->status === PaymentDocumentStatus::DRAFT) {
            // Если документ в черновике, не меняем статус
            return;
        } else {
            // Для других статусов оставляем как есть, если нет оплаты
            return;
        }

        if ($oldStatus !== $newStatus) {
            $document->update(['status' => $newStatus]);
            
            Log::info('payment_document.status_updated', [
                'document_id' => $document->id,
                'old_status' => $oldStatus->value,
                'new_status' => $newStatus->value,
                'paid_amount' => $document->paid_amount,
                'remaining_amount' => $document->remaining_amount,
            ]);
        }
    }

    /**
     * Создать документ из акта выполненных работ
     */
    public function createFromAct(\App\Models\ContractPerformanceAct $act, InvoiceDirection $direction): PaymentDocument
    {
        $contract = $act->contract;

        $data = [
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_date' => $act->act_date ?? now(),
            'due_date' => ($act->act_date ?? now())->addDays(30),
            'direction' => $direction,
            'invoice_type' => InvoiceType::ACT,
            'invoiceable_type' => \App\Models\ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
            'amount' => $act->amount ?? 0,
            'description' => "Счёт по акту №{$act->act_document_number}",
            'status' => PaymentDocumentStatus::SUBMITTED,
            'issued_at' => now(),
            'vat_rate' => 20,
        ];

        // Определить контрагента
        if ($direction === InvoiceDirection::OUTGOING) {
            // Мы должны оплатить подрядчику
            $data['contractor_id'] = $contract->contractor_id;
            $data['payee_contractor_id'] = $contract->contractor_id;
            $data['payer_organization_id'] = $contract->organization_id;
        } else {
            // Нам должны оплатить
            $data['counterparty_organization_id'] = $contract->contractor_id ? 
                \App\Models\Contractor::find($contract->contractor_id)?->source_organization_id : null;
            $data['payer_organization_id'] = $data['counterparty_organization_id'];
            $data['payee_organization_id'] = $contract->organization_id;
        }

        return $this->create($data);
    }

    /**
     * Создать документ из договора
     */
    public function createFromContract(Contract $contract, InvoiceType $type, array $additionalData = []): PaymentDocument
    {
        $data = array_merge([
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_date' => now(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => $type,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'contractor_id' => $contract->contractor_id,
            'payee_contractor_id' => $contract->contractor_id,
            'payer_organization_id' => $contract->organization_id,
            'description' => "Счёт по договору №{$contract->number}",
            'status' => PaymentDocumentStatus::SUBMITTED,
            'issued_at' => now(),
            'vat_rate' => 20,
        ], $additionalData);

        $document = $this->create($data);
        
        // Автоматически определяем получателя при создании из договора
        $this->detectAndSetRecipientOrganization($document);
        
        return $document;
    }

    /**
     * Определить и установить получателя-организацию для документа
     * 
     * Проверяет прямую связь через payee_organization_id или через подрядчика
     * Кэширует результат в recipient_organization_id для быстрого поиска
     * 
     * @param PaymentDocument $document Документ
     * @return void
     */
    public function detectAndSetRecipientOrganization(PaymentDocument $document): void
    {
        try {
            $recipientOrgId = $document->getRecipientOrganizationId();
            
            if ($recipientOrgId && $document->recipient_organization_id !== $recipientOrgId) {
                // Кэшируем ID организации-получателя для быстрого поиска
                $document->recipient_organization_id = $recipientOrgId;
                $document->saveQuietly(); // Сохраняем без событий, чтобы избежать циклов
                
                Log::debug('payment_document.recipient_detected', [
                    'document_id' => $document->id,
                    'recipient_org_id' => $recipientOrgId,
                ]);
            }
        } catch (\Exception $e) {
            // Не бросаем исключение - отсутствие получателя не должно ломать систему
            Log::warning('payment_document.recipient_detection_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

