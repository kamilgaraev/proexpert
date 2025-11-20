<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

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
                // Проверяем, что общая сумма платежей не превышает сумму договора
                $existingPaymentsSum = PaymentDocument::where('source_type', Contract::class)
                    ->where('source_id', $sourceId)
                    ->sum('amount');

                if (($existingPaymentsSum + $data['amount']) > $source->total_amount) {
                    throw new \DomainException('Сумма превышает остаток по договору');
                }
            }

            // Проверяем статус договора
            if (!in_array($source->status, ['active', 'executing'])) {
                throw new \DomainException('Договор должен быть активным');
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
        $similar = PaymentDocument::where('organization_id', $document->organization_id)
            ->where('id', '!=', $document->id)
            ->where('document_type', $document->document_type)
            ->where('amount', $document->amount)
            ->where('payee_contractor_id', $document->payee_contractor_id)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        if ($similar) {
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

        // Сумма всех платежей по договору
        $paidSum = PaymentDocument::where('source_type', Contract::class)
            ->where('source_id', $contract->id)
            ->where('id', '!=', $document->id)
            ->sum('amount');

        $totalWithCurrent = $paidSum + $document->amount;

        if ($totalWithCurrent > $contract->total_amount) {
            $remaining = $contract->total_amount - $paidSum;
            $errors[] = sprintf(
                'Превышена сумма договора. Остаток: %.2f, запрошено: %.2f',
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

