<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceStatus;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    public function __construct(
        private readonly CounterpartyAccountService $counterpartyService,
        private readonly PaymentAccessControl $accessControl,
    ) {}

    /**
     * Создать счёт
     */
    public function createInvoice(array $data): Invoice
    {
        // Валидация прав
        if (!$this->accessControl->canCreateInvoice($data['organization_id'], $data)) {
            throw new \DomainException('Нет прав на создание счёта');
        }

        // Автоматическая привязка к организации-контрагенту
        if (isset($data['contractor_id']) && !isset($data['counterparty_organization_id'])) {
            $contractor = DB::table('contractors')
                ->where('id', $data['contractor_id'])
                ->first(['contractor_type', 'source_organization_id']);
            
            if ($contractor && 
                $contractor->contractor_type === 'invited_organization' && 
                $contractor->source_organization_id) {
                $data['counterparty_organization_id'] = $contractor->source_organization_id;
            }
        }

        // Автоматический расчет суммы по шаблону
        if (isset($data['template_id']) && !isset($data['total_amount'])) {
            $data['total_amount'] = $this->calculateAmountFromTemplate(
                $data['template_id'],
                $data['invoiceable_type'] ?? null,
                $data['invoiceable_id'] ?? null
            );
        }

        // Автоматический расчёт remaining_amount
        $data['remaining_amount'] = $data['total_amount'] - ($data['paid_amount'] ?? 0);

        // Вызываем метод создания с retry механизмом
        return $this->createInvoiceWithRetry($data);
    }

    /**
     * Создать счёт с retry механизмом для обработки race conditions
     * 
     * Используется три уровня защиты от race condition:
     * 1. lockForUpdate() - блокировка записи на уровне БД
     * 2. Генерация номера внутри транзакции
     * 3. Retry механизм с экспоненциальной задержкой
     * 
     * @param array<string, mixed> $data Данные для создания счёта
     * @return Invoice
     * @throws \RuntimeException Если не удалось создать уникальный номер после всех попыток
     * 
     * @SuppressWarnings(PHPMD)
     */
    private function createInvoiceWithRetry(array $data): Invoice
    {
        /** @var int $maxRetries Maximum retry attempts */
        $maxRetries = 3;
        /** @var int $attempt Current attempt counter */
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                return DB::transaction(function () use (&$data) {
                    // Генерация номера внутри транзакции если не указан
                    if (!isset($data['invoice_number'])) {
                        $data['invoice_number'] = $this->generateInvoiceNumberWithLock($data['organization_id']);
                    }

                    $invoice = Invoice::create($data);

                    // Обновить баланс counterparty account если указан контрагент
                    if ($invoice->counterparty_organization_id) {
                        $this->counterpartyService->updateBalanceFromInvoice($invoice);
                    }

                    Log::info('payments.invoice.created', [
                        'invoice_id' => $invoice->id,
                        'organization_id' => $invoice->organization_id,
                        'amount' => $invoice->total_amount,
                        'counterparty_organization_id' => $invoice->counterparty_organization_id,
                        'template_id' => $data['template_id'] ?? null,
                    ]);

                    return $invoice;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Проверяем, что это ошибка дублирования номера
                $isDuplicateError = $e->getCode() === '23505' && 
                                   str_contains($e->getMessage(), 'invoice_number_unique');
                
                if ($isDuplicateError && $attempt < $maxRetries - 1) {
                    $attempt++;
                    
                    // Удалить закешированный номер и повторить попытку
                    unset($data['invoice_number']);
                    
                    Log::warning('payments.invoice.create.retry', [
                        'organization_id' => $data['organization_id'],
                        'attempt' => $attempt,
                    ]);
                    
                    // Экспоненциальная задержка перед повторной попыткой
                    usleep(100000 * $attempt); // 100ms, 200ms, 300ms
                    continue;
                }
                
                if ($isDuplicateError) {
                    Log::error('payments.invoice.create.max_retries_exceeded', [
                        'organization_id' => $data['organization_id'],
                        'attempts' => $attempt + 1,
                        'error' => $e->getMessage(),
                    ]);
                    throw new \RuntimeException('Не удалось создать уникальный номер счёта после нескольких попыток');
                }
                
                // Другие ошибки пробрасываем дальше
                throw $e;
            }
            
            $attempt++;
        }

        throw new \RuntimeException('Неожиданная ошибка при создании счёта');
    }

    /**
     * Создать счёт из акта выполненных работ
     */
    public function createFromAct(ContractPerformanceAct $act, InvoiceDirection $direction): Invoice
    {
        $contract = $act->contract;

        $data = [
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
            'invoice_date' => $act->act_date,
            'due_date' => $act->act_date->addDays(30), // По умолчанию 30 дней
            'direction' => $direction,
            'invoice_type' => InvoiceType::ACT,
            'total_amount' => $act->amount,
            'paid_amount' => 0,
            'remaining_amount' => $act->amount,
            'description' => "Счёт по акту №{$act->act_document_number}",
            'status' => InvoiceStatus::ISSUED,
            'issued_at' => now(),
        ];

        // Определить контрагента
        if ($direction === InvoiceDirection::OUTGOING) {
            // Мы должны оплатить подрядчику
            $data['contractor_id'] = $contract->contractor_id;
        }

        return $this->createInvoice($data);
    }

    /**
     * Создать счёт от договора
     */
    public function createFromContract(Contract $contract, InvoiceType $type, array $additionalData): Invoice
    {
        $data = array_merge([
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'invoice_date' => now(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => $type,
            'contractor_id' => $contract->contractor_id,
            'description' => "Счёт по договору №{$contract->number}",
            'status' => InvoiceStatus::ISSUED,
        ], $additionalData);

        return $this->createInvoice($data);
    }

    /**
     * Выставить счёт (перевести из draft в issued)
     */
    public function issueInvoice(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::DRAFT) {
            throw new \DomainException('Выставить можно только счёт в статусе "Черновик"');
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::ISSUED,
                'issued_at' => now(),
            ]);

            Log::info('payments.invoice.issued', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);
        });
    }

    /**
     * Обновить счёт
     */
    public function updateInvoice(Invoice $invoice, array $data): Invoice
    {
        if (!$this->accessControl->canUpdateInvoice($invoice->organization_id, $invoice)) {
            throw new \DomainException('Нет прав на обновление счёта');
        }

        // Нельзя изменять оплаченные счета
        if ($invoice->status === InvoiceStatus::PAID) {
            throw new \DomainException('Нельзя изменять оплаченный счёт');
        }

        return DB::transaction(function () use ($invoice, $data) {
            $invoice->update($data);

            // Пересчитать remaining_amount
            $invoice->remaining_amount = $invoice->calculateRemainingAmount();
            $invoice->save();

            return $invoice->fresh();
        });
    }

    /**
     * Отметить счёт как оплаченный
     */
    public function markAsPaid(Invoice $invoice, PaymentTransaction $transaction): void
    {
        DB::transaction(function () use ($invoice, $transaction) {
            $invoice->update([
                'paid_amount' => $invoice->total_amount,
                'remaining_amount' => 0,
                'status' => InvoiceStatus::PAID,
                'paid_at' => now(),
            ]);

            // Обновить баланс counterparty
            if ($invoice->counterparty_organization_id) {
                $this->counterpartyService->updateBalanceFromInvoice($invoice);
            }

            Log::info('payments.invoice.paid', [
                'invoice_id' => $invoice->id,
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
            ]);
        });
    }

    /**
     * Отметить счёт как просроченный
     */
    public function markAsOverdue(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::PAID) {
            return;
        }

        $invoice->update([
            'status' => InvoiceStatus::OVERDUE,
            'overdue_since' => now(),
        ]);

        // Обновить метрики counterparty
        if ($invoice->counterparty_organization_id) {
            $this->counterpartyService->incrementOverdueCount($invoice->counterparty_organization_id);
        }
    }

    /**
     * Отменить счёт
     */
    public function cancelInvoice(Invoice $invoice, string $reason): void
    {
        if (!$invoice->canBeCancelled()) {
            throw new \DomainException('Счёт не может быть отменён в текущем статусе');
        }

        DB::transaction(function () use ($invoice, $reason) {
            $invoice->update([
                'status' => InvoiceStatus::CANCELLED,
                'notes' => ($invoice->notes ?? '') . "\n\nПричина отмены: {$reason}",
            ]);

            // Обновить баланс counterparty
            if ($invoice->counterparty_organization_id) {
                $this->counterpartyService->updateBalanceFromInvoice($invoice);
            }
        });
    }

    /**
     * Получить неоплаченные счета
     */
    public function getOutstandingInvoices(int $organizationId, ?int $projectId = null): Collection
    {
        $query = Invoice::query()
            ->unpaid()
            ->with(['project', 'counterpartyOrganization', 'contractor']);

        $query = $this->accessControl->applyAccessScope($query, $organizationId);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return $query->orderBy('due_date')->get();
    }

    /**
     * Получить просроченные счета
     */
    public function getOverdueInvoices(int $organizationId, ?int $projectId = null): Collection
    {
        $query = Invoice::query()
            ->overdue()
            ->with(['project', 'counterpartyOrganization', 'contractor']);

        $query = $this->accessControl->applyAccessScope($query, $organizationId);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return $query->orderBy('due_date')->get();
    }

    /**
     * Рассчитать сумму по шаблону
     */
    private function calculateAmountFromTemplate(
        string $templateId,
        ?string $invoiceableType = null,
        ?int $invoiceableId = null
    ): float {
        // Проверка, что указан контракт для шаблонных платежей
        if ($invoiceableType !== 'App\\Models\\Contract' || !$invoiceableId) {
            throw new \DomainException('Для использования шаблонов необходимо указать контракт');
        }

        // Получить сумму контракта
        $contract = DB::table('contracts')
            ->where('id', $invoiceableId)
            ->first(['total_amount']);

        if (!$contract) {
            throw new \DomainException('Контракт не найден');
        }

        // Маппинг шаблонов к процентам
        $percentageMap = [
            'advance_30' => 30,
            'advance_50' => 50,
            'advance_70' => 70,
        ];

        if (!isset($percentageMap[$templateId])) {
            throw new \DomainException("Неизвестный шаблон: {$templateId}");
        }

        $percentage = $percentageMap[$templateId];
        return round(($contract->total_amount * $percentage) / 100, 2);
    }

    /**
     * Генерация уникального номера счёта с блокировкой
     * ВНИМАНИЕ: Должен вызываться только внутри DB::transaction()
     */
    private function generateInvoiceNumberWithLock(int $organizationId): string
    {
        $year = date('Y');
        $prefix = "INV-{$year}-";
        
        // Используем lockForUpdate() для блокировки последней записи
        // Это предотвращает race condition при параллельных запросах
        $lastInvoice = Invoice::where('organization_id', $organizationId)
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderBy('invoice_number', 'desc')
            ->lockForUpdate()
            ->first();

        if ($lastInvoice) {
            // Извлекаем числовую часть из номера
            $lastNumber = (int) str_replace($prefix, '', $lastInvoice->invoice_number);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Генерация уникального номера счёта (legacy метод, оставлен для обратной совместимости)
     * @deprecated Используйте generateInvoiceNumberWithLock() внутри транзакции
     */
    private function generateInvoiceNumber(int $organizationId): string
    {
        return $this->generateInvoiceNumberWithLock($organizationId);
    }
}

