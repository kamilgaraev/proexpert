<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentCreated;
use App\BusinessModules\Core\Payments\Events\PaymentRequestReceived;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function trans_message;

class PaymentDocumentService
{
    public function __construct(
        private readonly PaymentDocumentStateMachine $stateMachine,
        private readonly ApprovalWorkflowService $approvalWorkflow,
        private readonly PaymentValidationService $validator,
        private readonly PaymentBudgetLimitService $budgetLimitService,
        private readonly PaymentAuditService $auditService,
        private readonly PurchaseOrderContractRequirementService $contractRequirement,
        private readonly PaymentScheduleLedgerReconciliationService $scheduleReconciliation,
    ) {}

    /**
     * Создать платежный документ
     */
    public function create(array $data): PaymentDocument
    {
        $data = $this->normalizeDocumentData($data);
        $budgetOverrideReason = $data['budget_override_reason'] ?? null;
        unset($data['budget_override_reason']);

        if (isset($data['organization_id'])) {
            $data = $this->budgetLimitService->normalizeDocumentData($data, (int) $data['organization_id']);
        }

        $attempts = 0;
        $maxAttempts = 3;
        $wasNumberProvided = isset($data['document_number']);

        while ($attempts < $maxAttempts) {
            DB::beginTransaction();

            try {
                $data = $this->canonicalizeActInvoiceData($data);
                if (isset($data['origin_key'])) {
                    $existingDocument = PaymentDocument::query()
                        ->where('organization_id', $data['organization_id'])
                        ->where('origin_key', $data['origin_key'])
                        ->first();

                    if ($existingDocument instanceof PaymentDocument) {
                        DB::commit();

                        return $existingDocument;
                    }
                }

                // Валидация
                $this->validator->validate($data);

                // Генерация номера документа
                if (! isset($data['document_number'])) {
                    $data['document_number'] = $this->generateDocumentNumber(
                        $data['organization_id'],
                        PaymentDocumentType::from($data['document_type'])
                    );
                }

                // Расчет сумм с НДС
                $data = $this->calculateAmounts($data);

                // Создание документа
                $document = PaymentDocument::create($data);

                // Обрабатываем сплиты по позициям сметы
                if (! empty($data['estimate_splits'])) {
                    $this->processEstimateSplits($document, $data['estimate_splits']);
                }

                // Автоматически определяем и кэшируем получателя-организацию
                $this->detectAndSetRecipientOrganization($document);

                $createdBy = isset($data['created_by_user_id'])
                    ? User::find((int) $data['created_by_user_id'])
                    : null;
                $this->budgetLimitService->assertAllowed(
                    $document,
                    PaymentBudgetLimitService::OPERATION_CREATE,
                    (string) $document->amount,
                    $createdBy,
                    is_string($budgetOverrideReason) ? $budgetOverrideReason : null
                );
                $this->budgetLimitService->syncReservation(
                    $document->fresh(),
                    $createdBy,
                    is_string($budgetOverrideReason) ? $budgetOverrideReason : null
                );

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
                if ($isUniqueViolation
                    && str_contains($e->getMessage(), 'payment_documents_org_origin_unique')
                    && isset($data['origin_key'], $data['organization_id'])) {
                    $existingDocument = PaymentDocument::query()
                        ->where('organization_id', $data['organization_id'])
                        ->where('origin_key', $data['origin_key'])
                        ->first();
                    if ($existingDocument instanceof PaymentDocument
                        && BigDecimal::of((string) $existingDocument->amount)
                            ->isEqualTo(BigDecimal::of((string) $data['amount']))) {
                        return $existingDocument;
                    }

                    throw new \DomainException(trans_message('payments.validation.idempotency_conflict'));
                }
                $isDocumentNumberConstraint = str_contains($e->getMessage(), 'payment_documents_document_number_unique') ||
                                            str_contains($e->getMessage(), 'payment_documents_org_id_doc_num_unique');

                if ($isUniqueViolation && $isDocumentNumberConstraint) {
                    if (! $wasNumberProvided) {
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
                    ...$this->paymentDocumentFailureContext($data),
                    'exception' => $e::class,
                    'sql_state' => (string) $e->getCode(),
                ]);

                throw $e;
            } catch (\Exception $e) {
                DB::rollBack();

                Log::error('payment_document.create_failed', [
                    ...$this->paymentDocumentFailureContext($data),
                    'exception' => $e::class,
                ]);

                throw $e;
            }
        }

        throw new \Exception(sprintf(
            trans_message('payments.validation.document_create_attempts_exceeded'),
            $maxAttempts
        ));
    }

    /**
     * Обновить платежный документ
     */
    public function update(PaymentDocument $document, array $data): PaymentDocument
    {
        if (! $document->canBeEdited()) {
            throw new \DomainException(trans_message('payments.validation.document_edit_forbidden'));
        }

        $budgetOverrideReason = $data['budget_override_reason'] ?? null;
        unset($data['budget_override_reason']);
        $data = $this->budgetLimitService->normalizeDocumentData($data, (int) $document->organization_id);

        DB::beginTransaction();

        try {
            // Валидация изменений
            $this->validator->validate(array_merge($document->toArray(), $data), $document);

            // Перерасчет сумм если изменилась amount или vat_rate
            if (isset($data['amount']) || isset($data['vat_rate'])) {
                $data = $this->calculateAmounts($data, $document);
            }

            $document->update($data);
            $document->refresh();

            $user = auth()->user();
            $this->budgetLimitService->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_UPDATE,
                (string) $document->amount,
                $user instanceof User ? $user : null,
                is_string($budgetOverrideReason) ? $budgetOverrideReason : null
            );
            $this->budgetLimitService->syncReservation(
                $document,
                $user instanceof User ? $user : null,
                is_string($budgetOverrideReason) ? $budgetOverrideReason : null
            );

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
    public function submit(PaymentDocument $document, ?User $user = null, ?string $overrideReason = null): PaymentDocument
    {
        $effectiveOverrideReason = $overrideReason
            ?? (is_string($document->budget_limit_override_reason) ? $document->budget_limit_override_reason : null);

        DB::beginTransaction();

        try {
            // Финальная валидация перед отправкой
            $this->validator->validateBeforeSubmission($document);
            $this->budgetLimitService->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_SUBMIT,
                (string) $document->amount,
                $user,
                $effectiveOverrideReason
            );

            // Шаг 1: Переводим в статус "submitted" (отправлен на рассмотрение)
            $this->stateMachine->submit($document);

            // Шаг 2: Инициируем процесс утверждения (submitted → pending_approval)
            $this->approvalWorkflow->initiateApproval($document, $user, $effectiveOverrideReason);
            $this->budgetLimitService->syncReservation($document->fresh(), $user, $effectiveOverrideReason);

            Log::info('payment_document.submitted', [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
            ]);

            DB::commit();

            return $document->fresh();

        } catch (\DomainException $e) {
            DB::rollBack();

            Log::warning('payment_document.submit_rejected', [
                'document_id' => $document->id,
                'status' => $document->status?->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
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
    public function approve(
        PaymentDocument $document,
        ?int $approvedByUserId = null,
        ?string $budgetOverrideReason = null
    ): PaymentDocument {
        DB::beginTransaction();

        try {
            // Утверждаем документ через state machine
            $approvedBy = $approvedByUserId ? User::find($approvedByUserId) : null;

            $this->budgetLimitService->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_APPROVAL,
                (string) $document->amount,
                $approvedBy,
                $budgetOverrideReason
            );

            $this->stateMachine->approve($document, $approvedByUserId);
            $this->budgetLimitService->syncReservation(
                $document->fresh(),
                $approvedBy,
                $budgetOverrideReason
            );

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
    public function schedule(
        PaymentDocument $document,
        ?\DateTime $scheduledAt = null,
        ?User $user = null,
        ?string $overrideReason = null
    ): PaymentDocument {
        $oldScheduledAt = $document->scheduled_at?->format('Y-m-d');

        if (! in_array($document->status, [
            PaymentDocumentStatus::APPROVED,
            PaymentDocumentStatus::SCHEDULED,
            PaymentDocumentStatus::PARTIALLY_PAID,
        ], true)) {
            throw new \DomainException(trans_message('payments.validation.schedule_only_approved'));
        }

        $scheduledCarbon = $scheduledAt !== null
            ? \Illuminate\Support\Carbon::instance($scheduledAt)
            : null;

        $this->budgetLimitService->assertAllowed(
            $document,
            PaymentBudgetLimitService::OPERATION_SCHEDULE,
            (string) $document->remaining_amount,
            $user,
            $overrideReason,
            $scheduledCarbon
        );

        return DB::transaction(function () use ($document, $scheduledAt, $user, $overrideReason, $oldScheduledAt): PaymentDocument {
            $this->stateMachine->schedule($document, $scheduledAt);
            $freshDocument = $document->fresh();
            $this->budgetLimitService->syncReservation($freshDocument, $user, $overrideReason);
            $freshDocument = $freshDocument->fresh();

            $this->auditService->logRescheduled(
                $freshDocument,
                $oldScheduledAt,
                $freshDocument->scheduled_at?->format('Y-m-d'),
                $overrideReason
            );

            Log::info('payment_document.scheduled', [
                'document_id' => $document->id,
                'scheduled_at' => $scheduledAt?->format('Y-m-d H:i:s'),
            ]);

            return $freshDocument;
        });
    }

    /**
     * Зарегистрировать платеж
     */
    public function registerPayment(
        PaymentDocument $document,
        string|int|float $amount,
        array $paymentData
    ): PaymentDocument {
        $paymentAmount = BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp);

        Log::info('payment_document.register_payment.started', [
            'document_id' => $document->id,
            'amount' => (string) $paymentAmount,
        ]);

        DB::beginTransaction();

        try {
            $document = PaymentDocument::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();
            $idempotencyKey = $paymentData['idempotency_key']
                ?? (isset($paymentData['bank_transaction_id'])
                    ? 'bank:'.(string) $paymentData['bank_transaction_id']
                    : null);
            $bankTransactionId = trim((string) ($paymentData['bank_transaction_id'] ?? ''));

            if ($bankTransactionId !== '') {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                        "payment-bank:{$document->organization_id}:{$bankTransactionId}",
                    ]);
                }

                $existingBankTransaction = PaymentTransaction::query()
                    ->where('organization_id', $document->organization_id)
                    ->where('bank_transaction_id', $bankTransactionId)
                    ->first();

                if ($existingBankTransaction instanceof PaymentTransaction) {
                    $this->assertPaymentRetryMatches(
                        $existingBankTransaction,
                        $document,
                        $paymentAmount,
                        $paymentData
                    );
                    $this->scheduleReconciliation->reconcile($document->fresh(), $existingBankTransaction);
                    DB::commit();

                    return $document->fresh();
                }
            }

            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                        "payment:{$document->organization_id}:{$idempotencyKey}",
                    ]);
                }

                $existingTransaction = PaymentTransaction::query()
                    ->where('organization_id', $document->organization_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingTransaction instanceof PaymentTransaction) {
                    $this->assertPaymentRetryMatches($existingTransaction, $document, $paymentAmount, $paymentData);
                    $this->scheduleReconciliation->reconcile($document->fresh(), $existingTransaction);

                    DB::commit();

                    return $document->fresh();
                }
            }

            if (! $document->canBePaid()) {
                throw new \DomainException(trans_message('payments.validation.document_pay_forbidden'));
            }
            if (! $paymentAmount->isPositive()) {
                throw new \InvalidArgumentException(trans_message('payments.validation.payment_amount_positive'));
            }
            $this->contractRequirement->assertPaymentAllowed($document);
            Log::info('payment_document.register_payment.transaction_started', [
                'document_id' => $document->id,
            ]);

            // Проверка суммы
            $remainingBeforePayment = BigDecimal::of((string) $document->remaining_amount)
                ->toScale(2, RoundingMode::HalfUp);
            if ($paymentAmount->isGreaterThan($remainingBeforePayment)) {
                Log::warning('payment_document.register_payment.amount_exceeds', [
                    'document_id' => $document->id,
                    'amount' => (string) $paymentAmount,
                    'remaining_amount' => $document->remaining_amount,
                ]);
                throw new \DomainException(trans_message('payments.validation.payment_amount_exceeds_remaining'));
            }

            $operationDate = $this->paymentOperationDate($paymentData);
            $this->budgetLimitService->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_PAYMENT_REGISTER,
                (string) $paymentAmount,
                isset($paymentData['created_by_user_id']) ? User::find((int) $paymentData['created_by_user_id']) : null,
                $paymentData['budget_override_reason'] ?? null,
                $operationDate,
                true
            );

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
                'amount' => (string) $paymentAmount,
                'currency' => $document->currency,
                'payment_method' => $paymentData['payment_method'] ?? 'bank_transfer',
                'reference_number' => $paymentData['reference_number'] ?? null,
                'bank_transaction_id' => $paymentData['bank_transaction_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'transaction_date' => $paymentData['transaction_date'] ?? now(),
                'value_date' => $paymentData['value_date'] ?? now(),
                'status' => 'completed',
                'notes' => $paymentData['notes'] ?? null,
                'metadata' => $paymentData['metadata'] ?? [],
                'created_by_user_id' => $paymentData['created_by_user_id'] ?? null,
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

            $transactionModel = PaymentTransaction::query()->create($transactionData);
            $transaction = (int) $transactionModel->getKey();

            Log::info('payment_document.register_payment.transaction_inserted', [
                'document_id' => $document->id,
                'transaction_id' => $transaction,
            ]);

            Log::info('payment_document.register_payment.updating_amounts', [
                'document_id' => $document->id,
            ]);

            $newPaidAmount = BigDecimal::of((string) $document->paid_amount)
                ->plus($paymentAmount)
                ->toScale(2, RoundingMode::HalfUp);
            $newRemainingAmount = BigDecimal::of((string) $document->amount)
                ->minus($newPaidAmount)
                ->toScale(2, RoundingMode::HalfUp);

            Log::info('payment_document.register_payment.amounts_updated', [
                'document_id' => $document->id,
                'new_paid_amount' => (string) $newPaidAmount,
                'remaining_amount' => (string) $newRemainingAmount,
            ]);

            Log::info('payment_document.register_payment.transaction_loaded', [
                'document_id' => $document->id,
                'transaction_model_exists' => true,
            ]);

            Log::info('payment_document.register_payment.updating_status', [
                'document_id' => $document->id,
                'remaining_amount' => $newRemainingAmount,
            ]);

            // Определяем новый статус
            if (! $newRemainingAmount->isPositive()) {
                Log::info('payment_document.register_payment.marking_paid', [
                    'document_id' => $document->id,
                ]);
                $this->stateMachine->markPaid($document, (string) $newPaidAmount, $transaction);
            } else {
                Log::info('payment_document.register_payment.marking_partially_paid', [
                    'document_id' => $document->id,
                ]);
                $this->stateMachine->markPartiallyPaid($document, (string) $paymentAmount, $transaction);
            }

            $freshDocument = $document->fresh();
            $this->scheduleReconciliation->reconcile($freshDocument, $transactionModel);
            $this->budgetLimitService->convertAfterPayment($freshDocument, $transactionModel);
            $this->synchronizeEstimateItemsPaymentProgress($document);
            DB::afterCommit(fn () => $this->notifyPaymentRecipientAfterCommit($document->id, $transaction));

            Log::info('payment_document.payment_registered', [
                'document_id' => $document->id,
                'transaction_id' => $transaction,
                'amount' => (string) $paymentAmount,
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

    private function assertPaymentRetryMatches(
        PaymentTransaction $existing,
        PaymentDocument $document,
        BigDecimal $amount,
        array $paymentData
    ): void {
        $existingMethod = $existing->payment_method instanceof \BackedEnum
            ? $existing->payment_method->value
            : (string) $existing->payment_method;
        $expectedMethod = (string) ($paymentData['payment_method'] ?? 'bank_transfer');
        $expectedTransactionDate = $this->normalizedPaymentDate($paymentData['transaction_date'] ?? now());
        $expectedValueDate = $this->normalizedPaymentDate($paymentData['value_date'] ?? now());

        $matches = (int) $existing->payment_document_id === (int) $document->id
            && BigDecimal::of((string) $existing->amount)->toScale(2, RoundingMode::HalfUp)->isEqualTo($amount)
            && (string) $existing->currency === (string) $document->currency
            && $existingMethod === $expectedMethod
            && (string) ($existing->reference_number ?? '') === (string) ($paymentData['reference_number'] ?? '')
            && $this->normalizedPaymentDate($existing->transaction_date) === $expectedTransactionDate
            && $this->normalizedPaymentDate($existing->value_date) === $expectedValueDate;

        if (! $matches) {
            throw new \DomainException(trans_message('payments.validation.idempotency_conflict'));
        }
    }

    private function normalizedPaymentDate(mixed $value): string
    {
        return Carbon::parse($value)->toDateString();
    }

    private function notifyPaymentRecipientAfterCommit(int $documentId, int $transactionId): void
    {
        try {
            $document = PaymentDocument::query()->findOrFail($documentId);
            $transaction = PaymentTransaction::query()->findOrFail($transactionId);
            app(PaymentRecipientNotificationService::class)->notifyRecipientAboutPayment($document, $transaction);
        } catch (\Exception $e) {
            Log::warning('payment_document.notify_recipient_failed', [
                'document_id' => $documentId,
                'transaction_id' => $transactionId,
                'error_class' => $e::class,
            ]);
        }
    }

    /**
     * Отменить документ
     */
    public function cancel(PaymentDocument $document, string $reason, ?\App\Models\User $user = null): PaymentDocument
    {
        return DB::transaction(function () use ($document, $reason, $user): PaymentDocument {
            $document = PaymentDocument::query()
                ->where('organization_id', $document->organization_id)
                ->lockForUpdate()
                ->findOrFail($document->id);

            $isOrganizationOwner = $user?->isOrganizationOwner($document->organization_id) ?? false;

            if ($document->status !== PaymentDocumentStatus::CANCELLED) {
                if (! $document->canBeCancelled()) {
                    throw new \DomainException(trans_message('payments.validation.document_cancel_forbidden'));
                }

                $this->stateMachine->cancel($document, $reason);
            }

            $document->approvals()->where('status', 'pending')->update([
                'status' => 'skipped',
                'decision_comment' => $reason,
                'decided_at' => now(),
            ]);
            $this->budgetLimitService->release($document, $reason);

            Log::info('payment_document.cancelled', [
                'document_id' => $document->id,
                'reason' => $reason,
                'cancelled_by_owner' => $isOrganizationOwner,
                'user_id' => $user?->id,
            ]);

            return $document->fresh();
        });
    }

    /**
     * Удалить документ (soft delete)
     */
    public function delete(PaymentDocument $document): bool
    {
        // Проверяем, что документ не оплачен
        if ($document->status === PaymentDocumentStatus::PAID) {
            throw new \DomainException(trans_message('payments.validation.document_delete_paid_forbidden'));
        }

        // Проверяем, что нет транзакций
        if ($document->transactions()->count() > 0) {
            throw new \DomainException(trans_message('payments.validation.document_delete_has_transactions'));
        }

        $documentNumber = $document->document_number;
        $this->budgetLimitService->release($document, trans_message('payments.documents.deleted'));
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
            ->with([
                'project',
                'payerOrganization',
                'payeeOrganization',
                'payerContractor',
                'payeeContractor',
                'budgetArticle',
                'responsibilityCenter',
            ]);

        if (Schema::hasTable('site_requests') && Schema::hasTable('payment_document_site_requests')) {
            $query->withCount('siteRequests');
        }

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

        if (isset($filters['purchase_order_id'])) {
            $this->applyPurchaseOrderFilter($query, (int) $filters['purchase_order_id']);
        }

        if (isset($filters['contract_id'])) {
            $contractId = $filters['contract_id'];
            // Ищем документы, связанные с контрактом напрямую или через акт
            $query->where(function ($q) use ($contractId) {
                // Прямая связь с контрактом
                $q->where(function ($subQ) use ($contractId) {
                    $subQ->where('invoiceable_type', 'App\\Models\\Contract')
                        ->where('invoiceable_id', $contractId);
                })
                // Или связь через акт этого контракта
                    ->orWhere(function ($subQ) use ($contractId) {
                        $subQ->where('invoiceable_type', 'App\\Models\\ContractPerformanceAct')
                            ->whereExists(function ($existsQuery) use ($contractId) {
                                $existsQuery->select(DB::raw(1))
                                    ->from('contract_performance_acts')
                                    ->whereColumn('contract_performance_acts.id', 'payment_documents.invoiceable_id')
                                    ->where('contract_performance_acts.contract_id', $contractId);
                            });
                    });
            });
        }

        if (isset($filters['estimate_id'])) {
            $estimateId = (int) $filters['estimate_id'];

            $query->whereHas('estimateSplits.estimateItem', function ($estimateItemQuery) use ($estimateId): void {
                $estimateItemQuery->where('estimate_id', $estimateId);
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
            $query->where(function ($q) use ($search) {
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

    private function applyPurchaseOrderFilter(Builder $query, int $purchaseOrderId): void
    {
        $query->where(function (Builder $paymentQuery) use ($purchaseOrderId): void {
            $paymentQuery
                ->where('metadata->purchase_order_id', $purchaseOrderId)
                ->orWhere('metadata->purchase_order_id', (string) $purchaseOrderId)
                ->orWhereExists(function ($existsQuery) use ($purchaseOrderId): void {
                    $existsQuery
                        ->select(DB::raw(1))
                        ->from('purchase_orders')
                        ->where('purchase_orders.id', $purchaseOrderId)
                        ->whereColumn('purchase_orders.organization_id', 'payment_documents.organization_id')
                        ->whereNotNull('purchase_orders.contract_id')
                        ->where('payment_documents.invoice_type', InvoiceType::MATERIAL_PURCHASE->value)
                        ->where(function ($contractQuery): void {
                            $contractQuery
                                ->where(function ($sourceQuery): void {
                                    $sourceQuery
                                        ->where('payment_documents.source_type', Contract::class)
                                        ->whereColumn('payment_documents.source_id', 'purchase_orders.contract_id');
                                })
                                ->orWhere(function ($invoiceableQuery): void {
                                    $invoiceableQuery
                                        ->where('payment_documents.invoiceable_type', Contract::class)
                                        ->whereColumn('payment_documents.invoiceable_id', 'purchase_orders.contract_id');
                                });
                        });
                });
        });
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
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $sequence = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->where('document_type', $docType)
                ->whereYear('document_date', $year)
                ->whereMonth('document_date', $month)
                ->count() + 1;

            return sprintf('%s-%s%s-%04d', strtoupper(str_replace('_', '-', $docType)), $year, $month, $sequence);
        }

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
        $amount = BigDecimal::of((string) ($data['amount'] ?? $existingDocument?->amount ?? 0))
            ->toScale(2, RoundingMode::HalfUp);
        $vatRate = BigDecimal::of((string) ($data['vat_rate'] ?? $existingDocument?->vat_rate ?? 20));

        if (isset($data['amount_without_vat'], $data['vat_amount'])) {
            $amountWithoutVat = BigDecimal::of((string) $data['amount_without_vat'])
                ->toScale(2, RoundingMode::HalfUp);
            $vatAmount = $amount->minus($amountWithoutVat)->toScale(2, RoundingMode::HalfUp);
        } else {
            $divisor = BigDecimal::one()->plus($vatRate->dividedBy(100, 8, RoundingMode::HalfUp));
            $amountWithoutVat = $amount->dividedBy($divisor, 2, RoundingMode::HalfUp);
            $vatAmount = $amount->minus($amountWithoutVat)->toScale(2, RoundingMode::HalfUp);
        }

        $paidAmount = BigDecimal::of((string) ($data['paid_amount'] ?? $existingDocument?->paid_amount ?? 0))
            ->toScale(2, RoundingMode::HalfUp);
        $data['amount'] = (string) $amount;
        $data['amount_without_vat'] = (string) $amountWithoutVat;
        $data['vat_amount'] = (string) $vatAmount;
        $data['remaining_amount'] = (string) $amount->minus($paidAmount)->toScale(2, RoundingMode::HalfUp);

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
            'overdue_count' => $documents->filter(fn ($d) => $d->isOverdue())->count(),
            'overdue_amount' => $documents->filter(fn ($d) => $d->isOverdue())->sum('remaining_amount'),
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
    public function createFromAct(
        \App\Models\ContractPerformanceAct $act,
        InvoiceDirection $direction,
        string|float|int|null $amount = null,
        ?string $idempotencyKey = null
    ): PaymentDocument {
        $contract = $act->contract;

        $data = [
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_date' => $act->act_date ?? now(),
            'due_date' => ($act->act_date ?? now())->copy()->addDays(30),
            'direction' => $direction,
            'invoice_type' => InvoiceType::ACT,
            'invoiceable_type' => \App\Models\ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
            'amount' => $amount ?? $act->amount ?? 0,
            'description' => "Счёт по акту №{$act->act_document_number}",
            'status' => PaymentDocumentStatus::SUBMITTED,
            'issued_at' => now(),
            'vat_rate' => $act->vat_rate ?? 0,
            'idempotency_key' => $idempotencyKey ?? 'full',
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

    private function canonicalizeActInvoiceData(array $data): array
    {
        if (($data['invoiceable_type'] ?? null) !== \App\Models\ContractPerformanceAct::class
            || empty($data['invoiceable_id'])) {
            return $data;
        }

        $act = \App\Models\ContractPerformanceAct::query()
            ->with('contract')
            ->whereKey((int) $data['invoiceable_id'])
            ->lockForUpdate()
            ->firstOrFail();

        if (! $act->isReadyForPayment()) {
            throw new \DomainException(trans_message('payments.validation.act_not_ready_for_invoice'));
        }

        $contract = $act->contract;
        if (! $contract instanceof Contract
            || (int) ($data['organization_id'] ?? 0) !== (int) $contract->organization_id
            || (isset($data['project_id']) && (int) $data['project_id'] !== (int) $act->project_id)) {
            throw new \DomainException(trans_message('payments.validation.invoice_basis_scope_mismatch'));
        }

        $direction = (string) ($data['direction'] ?? InvoiceDirection::INCOMING->value);
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            throw new \DomainException(trans_message('payments.validation.invoice_idempotency_key_required'));
        }

        $originKey = sprintf(
            'performance-act:%d:%s:%s',
            $act->id,
            $direction,
            hash('sha256', $idempotencyKey)
        );
        $requestedAmount = BigDecimal::of((string) ($data['amount'] ?? $act->amount))
            ->toScale(2, RoundingMode::HalfUp);
        $existing = PaymentDocument::query()
            ->where('organization_id', $contract->organization_id)
            ->where('origin_key', $originKey)
            ->first();

        if ($existing instanceof PaymentDocument) {
            if (! BigDecimal::of((string) $existing->amount)->isEqualTo($requestedAmount)) {
                throw new \DomainException(trans_message('payments.validation.idempotency_conflict'));
            }

            unset($data['idempotency_key']);

            return array_merge($data, [
                'amount' => $existing->amount,
                'vat_rate' => $existing->vat_rate,
                'amount_without_vat' => $existing->amount_without_vat,
                'vat_amount' => $existing->vat_amount,
                'origin_key' => $originKey,
            ]);
        }

        if (! $requestedAmount->isPositive()) {
            throw new \DomainException(trans_message('payments.validation.amount_positive'));
        }

        $allocationQuery = PaymentDocument::query()
            ->where('organization_id', $contract->organization_id)
            ->where('invoiceable_type', \App\Models\ContractPerformanceAct::class)
            ->where('invoiceable_id', $act->id)
            ->where('direction', $direction)
            ->whereIn('status', [
                PaymentDocumentStatus::SUBMITTED->value,
                PaymentDocumentStatus::PENDING_APPROVAL->value,
                PaymentDocumentStatus::APPROVED->value,
                PaymentDocumentStatus::SCHEDULED->value,
                PaymentDocumentStatus::PARTIALLY_PAID->value,
                PaymentDocumentStatus::PAID->value,
            ]);
        $allocatedAmount = BigDecimal::of((string) (clone $allocationQuery)->sum('amount'))
            ->toScale(2, RoundingMode::HalfUp);
        $allocatedAmountWithoutVat = BigDecimal::of((string) (clone $allocationQuery)->sum('amount_without_vat'))
            ->toScale(2, RoundingMode::HalfUp);
        $allocatedVatAmount = BigDecimal::of((string) (clone $allocationQuery)->sum('vat_amount'))
            ->toScale(2, RoundingMode::HalfUp);
        $actAmount = BigDecimal::of((string) $act->amount)->toScale(2, RoundingMode::HalfUp);
        $availableAmount = $actAmount->minus($allocatedAmount)->toScale(2, RoundingMode::HalfUp);
        if ($requestedAmount->isGreaterThan($availableAmount)) {
            throw new \DomainException(trans_message('payments.validation.invoice_amount_exceeds_act_balance'));
        }

        $ratio = $requestedAmount->dividedBy($actAmount, 8, RoundingMode::HalfUp);
        $actAmountWithoutVat = BigDecimal::of((string) ($act->amount_without_vat ?? 0));
        if (! $actAmountWithoutVat->isPositive() && $actAmount->isPositive()) {
            $vatRate = BigDecimal::of((string) ($act->vat_rate ?? 0));
            $actAmountWithoutVat = $actAmount->dividedBy(
                BigDecimal::one()->plus($vatRate->dividedBy(100, 8, RoundingMode::HalfUp)),
                2,
                RoundingMode::HalfUp
            );
        }
        $actVatAmount = $act->vat_amount === null
            ? $actAmount->minus($actAmountWithoutVat)->toScale(2, RoundingMode::HalfUp)
            : BigDecimal::of((string) $act->vat_amount)->toScale(2, RoundingMode::HalfUp);
        if ($requestedAmount->isEqualTo($availableAmount)) {
            $amountWithoutVat = $actAmountWithoutVat
                ->minus($allocatedAmountWithoutVat)
                ->toScale(2, RoundingMode::HalfUp);
            $vatAmount = $actVatAmount
                ->minus($allocatedVatAmount)
                ->toScale(2, RoundingMode::HalfUp);
        } else {
            $amountWithoutVat = $actAmountWithoutVat->multipliedBy($ratio)->toScale(2, RoundingMode::HalfUp);
            $vatAmount = $requestedAmount->minus($amountWithoutVat)->toScale(2, RoundingMode::HalfUp);
        }
        unset($data['idempotency_key']);

        return array_merge($data, [
            'document_type' => PaymentDocumentType::INVOICE->value,
            'project_id' => $act->project_id,
            'invoice_type' => InvoiceType::ACT->value,
            'amount' => (string) $requestedAmount,
            'currency' => $act->currency ?: 'RUB',
            'vat_rate' => $act->vat_rate ?? 0,
            'amount_without_vat' => (string) $amountWithoutVat,
            'vat_amount' => (string) $vatAmount,
            'status' => PaymentDocumentStatus::SUBMITTED->value,
            'issued_at' => now(),
            'origin_key' => $originKey,
        ]);
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
            'currency' => config('payments.defaults.currency', 'RUB'),
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

    private function normalizeDocumentData(array $data): array
    {
        foreach (['document_type', 'direction', 'invoice_type', 'status'] as $field) {
            if (($data[$field] ?? null) instanceof \BackedEnum) {
                $data[$field] = $data[$field]->value;
            }
        }

        if (empty($data['currency'])) {
            $data['currency'] = config('payments.defaults.currency', 'RUB');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|string|null>
     */
    private function paymentDocumentFailureContext(array $data): array
    {
        return [
            'organization_id' => isset($data['organization_id']) ? (int) $data['organization_id'] : null,
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'document_type' => isset($data['document_type']) ? (string) $data['document_type'] : null,
            'source_type' => isset($data['source_type']) ? (string) $data['source_type'] : null,
            'source_id' => isset($data['source_id']) ? (int) $data['source_id'] : null,
            'invoiceable_type' => isset($data['invoiceable_type']) ? (string) $data['invoiceable_type'] : null,
            'invoiceable_id' => isset($data['invoiceable_id']) ? (int) $data['invoiceable_id'] : null,
        ];
    }

    private function paymentOperationDate(array $paymentData): \Illuminate\Support\Carbon
    {
        $date = $paymentData['transaction_date'] ?? $paymentData['payment_date'] ?? now();

        if ($date instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($date);
        }

        return \Illuminate\Support\Carbon::parse($date);
    }

    /**
     * Определить и установить получателя-организацию для документа
     *
     * Проверяет прямую связь через payee_organization_id или через подрядчика
     * Кэширует результат в recipient_organization_id для быстрого поиска
     *
     * @param  PaymentDocument  $document  Документ
     */
    public function detectAndSetRecipientOrganization(PaymentDocument $document): void
    {
        try {
            $recipientOrgId = $document->getRecipientOrganizationId();

            if ($recipientOrgId && $document->recipient_organization_id !== $recipientOrgId) {
                $document->recipient_organization_id = $recipientOrgId;
                $document->saveQuietly();

                Log::debug('payment_document.recipient_detected', [
                    'document_id' => $document->id,
                    'recipient_org_id' => $recipientOrgId,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('payment_document.recipient_detection_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function processEstimateSplits(PaymentDocument $document, array $splits): void
    {
        $this->ensureEstimateSplitsHavePaymentBasis($document);

        $affectedEstimateIds = [];

        foreach ($splits as $splitData) {
            $estimateItemId = (int) ($splitData['estimate_item_id'] ?? 0);
            $estimateItem = $this->resolveEstimateItemForDocument($document, $estimateItemId);

            if (! $estimateItem) {
                throw new \DomainException(sprintf(
                    trans_message('payments.validation.estimate_item_not_found'),
                    $estimateItemId
                ));
            }

            $quantity = (float) ($splitData['quantity'] ?? 0);
            $unitPricePlan = (float) $estimateItem->unit_price;
            $unitPriceActual = (float) ($splitData['unit_price_actual'] ?? $unitPricePlan);
            $amount = round($quantity * $unitPriceActual, 2);
            $priceDeviation = round(($unitPriceActual - $unitPricePlan) * $quantity, 2);

            \App\BusinessModules\Core\Payments\Models\PaymentDocumentEstimateSplit::create([
                'payment_document_id' => $document->id,
                'estimate_item_id' => $estimateItem->id,
                'quantity' => $quantity,
                'unit_price_plan' => $unitPricePlan,
                'unit_price_actual' => $unitPriceActual,
                'amount' => $splitData['amount'] ?? $amount,
                'percentage' => $splitData['percentage'] ?? 100,
                'price_deviation' => $priceDeviation,
            ]);

            if ($estimateItem->estimate_id) {
                $affectedEstimateIds[$estimateItem->estimate_id] = true;
            }
        }

        foreach (array_keys($affectedEstimateIds) as $estimateId) {
            \App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob::dispatch($estimateId);
        }

        Log::info('payment_document.splits_processed', [
            'document_id' => $document->id,
            'splits_count' => count($splits),
            'invalidated_estimates' => array_keys($affectedEstimateIds),
        ]);
    }

    private function ensureEstimateSplitsHavePaymentBasis(PaymentDocument $document): void
    {
        $allowedBasisTypes = [
            Contract::class,
            ContractPerformanceAct::class,
        ];

        if (
            $document->invoiceable_id
            && in_array($document->invoiceable_type, $allowedBasisTypes, true)
        ) {
            return;
        }

        if (
            $document->source_id
            && in_array($document->source_type, $allowedBasisTypes, true)
        ) {
            return;
        }

        throw new \DomainException(trans_message('payments.validation.estimate_split_source_required'));
    }

    private function resolveEstimateItemForDocument(PaymentDocument $document, int $estimateItemId): ?\App\Models\EstimateItem
    {
        if ($estimateItemId <= 0) {
            return null;
        }

        return \App\Models\EstimateItem::query()
            ->whereKey($estimateItemId)
            ->whereNull('estimate_items.deleted_at')
            ->whereHas('estimate', function ($query) use ($document) {
                $query->where('organization_id', $document->organization_id)
                    ->when($document->estimate_id, fn ($builder) => $builder->where('id', $document->estimate_id))
                    ->when($document->project_id, fn ($builder) => $builder->where('project_id', $document->project_id));
            })
            ->first();
    }

    public function analyzePriceDeviation(array $splits): array
    {
        return app(PriceDeviationAnalyzer::class)->analyze($splits);
    }

    public function synchronizeFinancialProjections(PaymentDocument $document): void
    {
        $this->synchronizeEstimateItemsPaymentProgress($document->fresh());
    }

    private function synchronizeEstimateItemsPaymentProgress(PaymentDocument $document): void
    {
        $document->loadMissing('estimateSplits');

        $estimateItemIds = $document->estimateSplits
            ->pluck('estimate_item_id')
            ->filter()
            ->unique()
            ->values();

        if ($estimateItemIds->isEmpty()) {
            return;
        }

        $affectedEstimateIds = [];

        foreach ($estimateItemIds as $estimateItemId) {
            $estimateItem = \App\Models\EstimateItem::query()
                ->whereKey($estimateItemId)
                ->first();

            if (! $estimateItem) {
                continue;
            }

            $splits = \App\BusinessModules\Core\Payments\Models\PaymentDocumentEstimateSplit::query()
                ->where('estimate_item_id', $estimateItemId)
                ->whereHas('document', function ($query): void {
                    $query->whereIn('status', [
                        PaymentDocumentStatus::PAID->value,
                        PaymentDocumentStatus::PARTIALLY_PAID->value,
                    ]);
                })
                ->with('document')
                ->get();

            $paidQuantity = BigDecimal::zero();
            $paidAmount = BigDecimal::zero();

            foreach ($splits as $split) {
                $splitDocument = $split->document;

                if (! $splitDocument) {
                    continue;
                }

                $documentAmount = BigDecimal::of((string) $splitDocument->amount);
                $documentPaidAmount = BigDecimal::of((string) $splitDocument->paid_amount);
                if ($documentAmount->isLessThanOrEqualTo(0) || $documentPaidAmount->isLessThanOrEqualTo(0)) {
                    continue;
                }

                $paymentRatio = $documentPaidAmount->dividedBy($documentAmount, 18, RoundingMode::HALF_UP);
                if ($paymentRatio->isGreaterThan(1)) {
                    $paymentRatio = BigDecimal::one();
                }
                $splitPaidQuantity = BigDecimal::of((string) ($split->quantity ?? 0))
                    ->multipliedBy($paymentRatio);

                $paidQuantity = $paidQuantity->plus($splitPaidQuantity);
                $paidAmount = $paidAmount->plus(
                    BigDecimal::of((string) ($split->amount ?? 0))->multipliedBy($paymentRatio)
                );
            }

            $plannedQuantity = BigDecimal::of((string) ($estimateItem->quantity_total ?? $estimateItem->quantity ?? 0));
            $hasPaidQuantity = $paidQuantity->isGreaterThan(0);
            $actualUnitPrice = $hasPaidQuantity
                ? (string) $paidAmount->dividedBy($paidQuantity, 4, RoundingMode::HALF_UP)
                : null;

            $estimateItem->update([
                'actual_unit_price' => $actualUnitPrice,
                'actual_quantity' => $hasPaidQuantity
                    ? (string) $paidQuantity->toScale(8, RoundingMode::HALF_UP)
                    : null,
                'procurement_status' => $this->resolveEstimateItemPaymentStatus($paidQuantity, $plannedQuantity),
            ]);

            if ($estimateItem->estimate_id) {
                $affectedEstimateIds[$estimateItem->estimate_id] = true;
            }
        }

        foreach (array_keys($affectedEstimateIds) as $estimateId) {
            \App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob::dispatch($estimateId);
        }
    }

    private function resolveEstimateItemPaymentStatus(BigDecimal $paidQuantity, BigDecimal $plannedQuantity): string
    {
        $epsilon = BigDecimal::of('0.00000001');

        if ($paidQuantity->isLessThanOrEqualTo($epsilon)) {
            return 'pending';
        }

        if ($plannedQuantity->isGreaterThan(0)
            && $paidQuantity->plus($epsilon)->isGreaterThanOrEqualTo($plannedQuantity)) {
            return 'paid';
        }

        return 'ordered';
    }
}
