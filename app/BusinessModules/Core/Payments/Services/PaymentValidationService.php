<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentValidationService
{
    /**
     * Валидация данных платежного документа
     */
    public function validate(array $data, ?PaymentDocument $existingDocument = null): void
    {
        // Базовая валидация
        $this->validateRequired($data);
        $this->validateAmounts($data);
        $this->validateDates($data);
        $this->validateParties($data);
        
        // Валидация уникальности номера документа (если передан)
        if (isset($data['document_number'])) {
            $this->validateDocumentNumber($data['document_number'], $data['organization_id'], $existingDocument);
        }
        
        // Бизнес-валидация
        $this->validateBankDetails($data);
        
        if (isset($data['source_type']) && isset($data['source_id'])) {
            $this->validateSource($data['source_type'], $data['source_id'], $data);
        }

        if ($existingDocument && isset($data['amount'])) {
            $this->validateAmountChange($existingDocument, $data['amount']);
        }
    }

    /**
     * Валидация перед отправкой на утверждение
     */
    public function validateBeforeSubmission(PaymentDocument $document): void
    {
        $errors = [];

        // Проверка обязательных полей
        if (!$document->payer_organization_id && !$document->payer_contractor_id) {
            $errors[] = 'Не указан плательщик';
        }

        if (!$document->payee_organization_id && !$document->payee_contractor_id) {
            $errors[] = 'Не указан получатель';
        }

        if (!$document->amount || $document->amount <= 0) {
            $errors[] = 'Некорректная сумма документа';
        }

        if (!$document->payment_purpose && $document->document_type->isOutgoing()) {
            $errors[] = 'Не указано назначение платежа';
        }

        // Проверка банковских реквизитов для исходящих платежей
        if ($document->document_type->isOutgoing()) {
            if (!$document->bank_account) {
                $errors[] = 'Не указан расчетный счет получателя';
            }

            if (!$document->bank_bik) {
                $errors[] = 'Не указан БИК банка получателя';
            }
        }

        // Проверка дубликатов
        $this->checkDuplicates($document, $errors);

        // Проверка кредитного лимита
        $this->checkCreditLimit($document, $errors);

        // Проверка остатка на договоре
        if ($document->source_type === Contract::class && $document->source_id) {
            $this->checkContractBalance($document, $errors);
        }

        // Проверка баланса счета организации (для исходящих)
        if ($document->document_type->isOutgoing()) {
            $this->checkAccountBalance($document, $errors);
        }

        if (!empty($errors)) {
            throw new \DomainException('Ошибки валидации: ' . implode('; ', $errors));
        }
    }

    /**
     * Валидация обязательных полей
     */
    private function validateRequired(array $data): void
    {
        $required = ['organization_id', 'document_type', 'document_date', 'amount', 'currency'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("Поле '{$field}' обязательно");
            }
        }
    }

    /**
     * Валидация сумм
     */
    private function validateAmounts(array $data): void
    {
        if (isset($data['amount']) && $data['amount'] <= 0) {
            throw new \InvalidArgumentException('Сумма должна быть больше 0');
        }

        if (isset($data['vat_rate']) && ($data['vat_rate'] < 0 || $data['vat_rate'] > 100)) {
            throw new \InvalidArgumentException('НДС должен быть от 0 до 100%');
        }

        if (isset($data['paid_amount']) && $data['paid_amount'] < 0) {
            throw new \InvalidArgumentException('Оплаченная сумма не может быть отрицательной');
        }

        if (isset($data['amount']) && isset($data['paid_amount']) && $data['paid_amount'] > $data['amount']) {
            throw new \InvalidArgumentException('Оплаченная сумма не может превышать общую сумму');
        }
    }

    /**
     * Валидация дат
     */
    private function validateDates(array $data): void
    {
        if (isset($data['document_date'])) {
            $docDate = is_string($data['document_date']) 
                ? \Carbon\Carbon::parse($data['document_date']) 
                : $data['document_date'];

            // Дата документа не может быть в далеком будущем (более 30 дней)
            if ($docDate->gt(now()->addDays(30))) {
                throw new \InvalidArgumentException('Дата документа не может быть более чем на 30 дней вперед');
            }
        }

        if (isset($data['due_date']) && isset($data['document_date'])) {
            $docDate = is_string($data['document_date']) 
                ? \Carbon\Carbon::parse($data['document_date']) 
                : $data['document_date'];
                
            $dueDate = is_string($data['due_date']) 
                ? \Carbon\Carbon::parse($data['due_date']) 
                : $data['due_date'];

            if ($dueDate->lt($docDate)) {
                throw new \InvalidArgumentException('Срок оплаты не может быть раньше даты документа');
            }
        }
    }

    /**
     * Валидация сторон сделки
     */
    private function validateParties(array $data): void
    {
        // Проверка что указан хотя бы один плательщик
        if (!isset($data['payer_organization_id']) && !isset($data['payer_contractor_id'])) {
            throw new \InvalidArgumentException('Не указан плательщик');
        }

        // Проверка что указан хотя бы один получатель
        if (!isset($data['payee_organization_id']) && !isset($data['payee_contractor_id'])) {
            throw new \InvalidArgumentException('Не указан получатель');
        }

        // Проверка существования организаций
        if (isset($data['payer_organization_id'])) {
            if (!Organization::find($data['payer_organization_id'])) {
                throw new \InvalidArgumentException('Организация-плательщик не найдена');
            }
        }

        if (isset($data['payee_organization_id'])) {
            if (!Organization::find($data['payee_organization_id'])) {
                throw new \InvalidArgumentException('Организация-получатель не найдена');
            }
        }

        // Проверка существования контрагентов
        if (isset($data['payer_contractor_id'])) {
            if (!Contractor::find($data['payer_contractor_id'])) {
                throw new \InvalidArgumentException('Контрагент-плательщик не найден');
            }
        }

        if (isset($data['payee_contractor_id'])) {
            if (!Contractor::find($data['payee_contractor_id'])) {
                throw new \InvalidArgumentException('Контрагент-получатель не найден');
            }
        }
    }

    /**
     * Валидация уникальности номера документа
     */
    private function validateDocumentNumber(string $documentNumber, int $organizationId, ?PaymentDocument $existingDocument = null): void
    {
        $query = PaymentDocument::where('organization_id', $organizationId)
            ->where('document_number', $documentNumber);

        // Если это обновление, исключаем текущий документ
        if ($existingDocument) {
            $query->where('id', '!=', $existingDocument->id);
        }

        $duplicate = $query->first();

        if ($duplicate) {
            Log::warning('payment_document.duplicate_number', [
                'document_number' => $documentNumber,
                'organization_id' => $organizationId,
                'existing_document_id' => $duplicate->id,
                'existing_document_status' => $duplicate->status->value,
            ]);

            throw new \InvalidArgumentException(
                "Документ с номером '{$documentNumber}' уже существует для данной организации. " .
                "Пожалуйста, используйте другой номер или оставьте поле пустым для автоматической генерации."
            );
        }
    }

    /**
     * Валидация банковских реквизитов
     */
    private function validateBankDetails(array $data): void
    {
        // БИК должен быть 9 цифр
        if (isset($data['bank_bik'])) {
            if (!preg_match('/^\d{9}$/', $data['bank_bik'])) {
                throw new \InvalidArgumentException('БИК должен содержать 9 цифр');
            }
        }

        // Расчетный счет должен быть 20 цифр
        if (isset($data['bank_account'])) {
            if (!preg_match('/^\d{20}$/', $data['bank_account'])) {
                throw new \InvalidArgumentException('Расчетный счет должен содержать 20 цифр');
            }
        }

        // Корр. счет должен быть 20 цифр
        if (isset($data['bank_correspondent_account'])) {
            if (!preg_match('/^\d{20}$/', $data['bank_correspondent_account'])) {
                throw new \InvalidArgumentException('Корреспондентский счет должен содержать 20 цифр');
            }
        }
    }

    /**
     * Валидация источника документа
     */
    private function validateSource(string $sourceType, int $sourceId, array $data): void
    {
        $source = $sourceType::find($sourceId);

        if (!$source) {
            throw new \InvalidArgumentException('Источник документа не найден');
        }

        // Проверка для договоров
        if ($sourceType === Contract::class) {
            if (isset($data['amount']) && $source->total_amount > 0) {
                // Проверяем, мультипроектный ли договор и передан ли project_id
                $projectId = $data['project_id'] ?? null;
                $isMultiProject = $source->is_multi_project ?? false;
                
                Log::info('payment_validation.contract_check', [
                    'contract_id' => $sourceId,
                    'is_multi_project' => $isMultiProject,
                    'project_id' => $projectId,
                    'amount' => $data['amount'],
                ]);
                
                // Базовый запрос для подсчета платежей
                $paymentsQuery = PaymentDocument::where('source_type', Contract::class)
                    ->where('source_id', $sourceId);
                
                // Для мультипроектных договоров фильтруем по проекту
                if ($isMultiProject && $projectId) {
                    $paymentsQuery->where('project_id', $projectId);
                }
                
                $existingPaymentsSum = (clone $paymentsQuery)->sum('amount');

                // Определяем тип платежа
                $invoiceTypeValue = null;
                if (isset($data['invoice_type'])) {
                    $invoiceTypeValue = is_object($data['invoice_type']) 
                        ? $data['invoice_type']->value 
                        : $data['invoice_type'];
                }
                
                $isAdvancePayment = $invoiceTypeValue === 'advance';
                $isFinalPayment = $invoiceTypeValue === 'final';
                
                if ($isAdvancePayment) {
                    // Для авансовых платежей проверяем плановую сумму аванса
                    if ($source->planned_advance_amount > 0) {
                        $advanceQuery = PaymentDocument::where('source_type', Contract::class)
                            ->where('source_id', $sourceId)
                            ->where('invoice_type', 'advance');
                        
                        if ($isMultiProject && $projectId) {
                            $advanceQuery->where('project_id', $projectId);
                        }
                        
                        $existingAdvancePayments = $advanceQuery->sum('amount');
                        $totalAdvanceWithCurrent = $existingAdvancePayments + $data['amount'];
                        
                        if ($totalAdvanceWithCurrent > $source->planned_advance_amount) {
                            throw new \DomainException(
                                sprintf(
                                    'Сумма авансовых платежей превышает плановый аванс. Доступно: %.2f, запрошено: %.2f',
                                    $source->planned_advance_amount - $existingAdvancePayments,
                                    $data['amount']
                                )
                            );
                        }
                    }
                } elseif (!$isFinalPayment) {
                    // Для обычных платежей (кроме финального расчета) проверяем выполненные работы
                    // Для мультипроектных договоров - только по текущему проекту
                    
                    if ($isMultiProject && $projectId) {
                        // Для мультипроектного договора считаем акты только по текущему проекту
                        $performedAmount = DB::table('contract_performance_acts')
                            ->where('contract_id', $sourceId)
                            ->where('project_id', $projectId)
                            ->where('is_approved', true)
                            ->sum('amount');
                    } else {
                        // Для обычного договора берем все акты
                        $performedAmount = $source->total_performed_amount ?? 0;
                    }
                    
                    // Получаем сумму неавансовых платежей (исключая финальные расчеты)
                    $regularPaymentsQuery = PaymentDocument::where('source_type', Contract::class)
                        ->where('source_id', $sourceId)
                        ->where(function($query) {
                            $query->whereNull('invoice_type')
                                  ->orWhereNotIn('invoice_type', ['advance', 'final']);
                        });
                    
                    if ($isMultiProject && $projectId) {
                        $regularPaymentsQuery->where('project_id', $projectId);
                    }
                    
                    $existingRegularPayments = $regularPaymentsQuery->sum('amount');
                    $totalRegularWithCurrent = $existingRegularPayments + $data['amount'];
                    
                    Log::info('payment_validation.performed_amount_check', [
                        'is_multi_project' => $isMultiProject,
                        'project_id' => $projectId,
                        'performed_amount' => $performedAmount,
                        'existing_regular_payments' => $existingRegularPayments,
                        'requested_amount' => $data['amount'],
                        'total_with_current' => $totalRegularWithCurrent,
                    ]);
                    
                    if ($totalRegularWithCurrent > $performedAmount) {
                        $projectInfo = $isMultiProject && $projectId ? " (проект #{$projectId})" : '';
                        throw new \DomainException(
                            sprintf(
                                'Сумма превышает объем выполненных работ%s. Выполнено: %.2f, уже оплачено: %.2f, доступно: %.2f, запрошено: %.2f',
                                $projectInfo,
                                $performedAmount,
                                $existingRegularPayments,
                                $performedAmount - $existingRegularPayments,
                                $data['amount']
                            )
                        );
                    }
                }
                // Для финального расчета проверка идет только по общей сумме договора (см. ниже)
                
                // Общая проверка: сумма всех платежей не должна превышать общую сумму договора
                // Для мультипроектных договоров проверяем по проекту, если указан
                $totalWithCurrent = $existingPaymentsSum + $data['amount'];
                
                if ($totalWithCurrent > $source->total_amount) {
                    $projectInfo = $isMultiProject && $projectId ? " (проект #{$projectId})" : '';
                    throw new \DomainException(
                        sprintf(
                            'Сумма всех платежей превышает общую сумму договора%s. Договор: %.2f, уже оплачено: %.2f, доступно: %.2f, запрошено: %.2f',
                            $projectInfo,
                            $source->total_amount,
                            $existingPaymentsSum,
                            $source->total_amount - $existingPaymentsSum,
                            $data['amount']
                        )
                    );
                }
            }

            // Проверяем статус договора
            // status может быть как строкой, так и Enum объектом
            $statusValue = is_object($source->status) ? $source->status->value : $source->status;
            
            if (!in_array($statusValue, ['active', 'draft'])) {
                throw new \DomainException('Договор должен быть активным или в статусе черновика');
            }
        }
    }

    /**
     * Валидация изменения суммы
     */
    private function validateAmountChange(PaymentDocument $document, float $newAmount): void
    {
        // Если есть оплаты, новая сумма не может быть меньше оплаченной
        if ($newAmount < $document->paid_amount) {
            throw new \DomainException('Новая сумма не может быть меньше уже оплаченной суммы');
        }

        // Если документ утвержден или оплачен, нельзя менять сумму
        if (in_array($document->status->value, ['approved', 'scheduled', 'paid', 'partially_paid'])) {
            throw new \DomainException('Нельзя изменить сумму утвержденного или оплаченного документа');
        }
    }

    /**
     * Проверка дубликатов
     */
    private function checkDuplicates(PaymentDocument $document, array &$errors): void
    {
        // Ищем похожие документы за последние 30 дней
        // Исключаем документы со статусами: draft, cancelled, rejected
        $similarDocuments = PaymentDocument::where('organization_id', $document->organization_id)
            ->where('id', '!=', $document->id)
            ->where('document_type', $document->document_type)
            ->where('amount', $document->amount)
            ->where('payee_contractor_id', $document->payee_contractor_id)
            ->whereNotIn('status', ['draft', 'cancelled', 'rejected'])
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['id', 'document_number', 'status', 'created_at']);

        if ($similarDocuments->isNotEmpty()) {
            Log::warning('payment_document.duplicate_check', [
                'current_document_id' => $document->id,
                'current_document_number' => $document->document_number,
                'current_status' => $document->status->value,
                'amount' => $document->amount,
                'payee_contractor_id' => $document->payee_contractor_id,
                'similar_documents' => $similarDocuments->map(fn($doc) => [
                    'id' => $doc->id,
                    'document_number' => $doc->document_number,
                    'status' => $doc->status->value,
                    'created_at' => $doc->created_at->toDateTimeString(),
                ])->toArray(),
            ]);

            $errors[] = 'Возможный дубликат: найден похожий документ с той же суммой и получателем';
        }
    }

    /**
     * Проверка кредитного лимита контрагента
     */
    private function checkCreditLimit(PaymentDocument $document, array &$errors): void
    {
        if (!$document->payee_contractor_id) {
            return;
        }

        $account = DB::table('counterparty_accounts')
            ->where('organization_id', $document->organization_id)
            ->where('counterparty_contractor_id', $document->payee_contractor_id)
            ->first();

        if (!$account) {
            return; // нет счета = нет лимита
        }

        if ($account->is_blocked) {
            $errors[] = 'Контрагент заблокирован';
            return;
        }

        if ($account->credit_limit > 0) {
            $currentDebt = $account->balance_payable ?? 0;
            $newDebt = $currentDebt + $document->amount;

            if ($newDebt > $account->credit_limit) {
                $errors[] = sprintf(
                    'Превышен кредитный лимит контрагента: %.2f / %.2f',
                    $newDebt,
                    $account->credit_limit
                );
            }
        }
    }

    /**
     * Проверка остатка по договору
     */
    private function checkContractBalance(PaymentDocument $document, array &$errors): void
    {
        $contract = Contract::find($document->source_id);

        if (!$contract || $contract->total_amount <= 0) {
            return;
        }

        // Проверяем, мультипроектный ли договор и передан ли project_id
        $projectId = $document->project_id ?? null;
        $isMultiProject = $contract->is_multi_project ?? false;
        
        // Базовый запрос для подсчета платежей
        $paymentsQuery = PaymentDocument::where('source_type', Contract::class)
            ->where('source_id', $contract->id)
            ->where('id', '!=', $document->id);
        
        // Для мультипроектных договоров фильтруем по проекту
        if ($isMultiProject && $projectId) {
            $paymentsQuery->where('project_id', $projectId);
        }
        
        $paidSum = $paymentsQuery->sum('amount');

        // Определяем тип платежа
        $invoiceTypeValue = null;
        if ($document->invoice_type) {
            $invoiceTypeValue = is_object($document->invoice_type) 
                ? $document->invoice_type->value 
                : $document->invoice_type;
        }
        
        $isAdvancePayment = $invoiceTypeValue === 'advance';
        $isFinalPayment = $invoiceTypeValue === 'final';
        
        // Для обычных платежей (не аванс и не финальный расчет) проверяем по актам
        if (!$isAdvancePayment && !$isFinalPayment) {
            if ($isMultiProject && $projectId) {
                // Для мультипроектного договора считаем акты только по текущему проекту
                $performedAmount = DB::table('contract_performance_acts')
                    ->where('contract_id', $contract->id)
                    ->where('project_id', $projectId)
                    ->where('is_approved', true)
                    ->sum('amount');
            } else {
                // Для обычного договора берем все акты
                $performedAmount = $contract->total_performed_amount ?? 0;
            }
            
            // Получаем сумму неавансовых платежей (исключая финальные расчеты)
            $regularPaymentsQuery = PaymentDocument::where('source_type', Contract::class)
                ->where('source_id', $contract->id)
                ->where('id', '!=', $document->id)
                ->where(function($query) {
                    $query->whereNull('invoice_type')
                          ->orWhereNotIn('invoice_type', ['advance', 'final']);
                });
            
            if ($isMultiProject && $projectId) {
                $regularPaymentsQuery->where('project_id', $projectId);
            }
            
            $existingRegularPayments = $regularPaymentsQuery->sum('amount');
            $totalRegularWithCurrent = $existingRegularPayments + $document->amount;
            
            if ($totalRegularWithCurrent > $performedAmount) {
                $projectInfo = $isMultiProject && $projectId ? " (проект #{$projectId})" : '';
                $errors[] = sprintf(
                    'Сумма превышает объем выполненных работ%s. Выполнено: %.2f, уже оплачено: %.2f, доступно: %.2f, запрошено: %.2f',
                    $projectInfo,
                    $performedAmount,
                    $existingRegularPayments,
                    $performedAmount - $existingRegularPayments,
                    $document->amount
                );
                return;
            }
        }
        
        // Общая проверка по сумме договора
        $totalWithCurrent = $paidSum + $document->amount;

        if ($totalWithCurrent > $contract->total_amount) {
            $remaining = $contract->total_amount - $paidSum;
            $projectInfo = $isMultiProject && $projectId ? " (проект #{$projectId})" : '';
            $errors[] = sprintf(
                'Превышена сумма договора%s. Остаток: %.2f, запрошено: %.2f',
                $projectInfo,
                $remaining,
                $document->amount
            );
        }
    }

    /**
     * Проверка баланса счета организации
     */
    private function checkAccountBalance(PaymentDocument $document, array &$errors): void
    {
        // TODO: Реализовать проверку баланса когда появится таблица счетов организации
        // Пока пропускаем эту проверку
        return;
        
        // Получаем текущий баланс организации (упрощенно)
        try {
            $balance = DB::table('organizations')
                ->where('id', $document->organization_id)
                ->value('account_balance');

            if ($balance === null) {
                return; // Баланс не отслеживается
            }

            // Получаем запланированные платежи
            $scheduledPayments = PaymentDocument::where('organization_id', $document->organization_id)
                ->where('id', '!=', $document->id)
                ->whereIn('status', ['approved', 'scheduled'])
                ->sum('remaining_amount');

            $availableBalance = $balance - $scheduledPayments;

            if ($document->amount > $availableBalance) {
                $errors[] = sprintf(
                    'Недостаточно средств на счете. Доступно: %.2f, требуется: %.2f',
                    $availableBalance,
                    $document->amount
                );
            }
        } catch (\Exception $e) {
            // Если колонка не существует или другая ошибка - просто пропускаем проверку
            return;
        }
    }
}

