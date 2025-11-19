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

        // Автоматический расчет суммы по шаблону
        if (isset($data['template_id']) && !isset($data['total_amount'])) {
            $data['total_amount'] = $this->calculateAmountFromTemplate(
                $data['template_id'],
                $data['invoiceable_type'] ?? null,
                $data['invoiceable_id'] ?? null
            );
        }

        // Генерация номера если не указан
        if (!isset($data['invoice_number'])) {
            $data['invoice_number'] = $this->generateInvoiceNumber($data['organization_id']);
        }

        // Автоматический расчёт remaining_amount
        $data['remaining_amount'] = $data['total_amount'] - ($data['paid_amount'] ?? 0);

        return DB::transaction(function () use ($data) {
            $invoice = Invoice::create($data);

            // Обновить баланс counterparty account если указан контрагент
            if ($invoice->counterparty_organization_id) {
                $this->counterpartyService->updateBalanceFromInvoice($invoice);
            }

            \Log::info('payments.invoice.created', [
                'invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
                'amount' => $invoice->total_amount,
                'template_id' => $data['template_id'] ?? null,
            ]);

            return $invoice;
        });
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

            \Log::info('payments.invoice.paid', [
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
     * Генерация уникального номера счёта
     */
    private function generateInvoiceNumber(int $organizationId): string
    {
        $year = date('Y');
        $prefix = "INV-{$year}-";
        
        $lastInvoice = Invoice::where('organization_id', $organizationId)
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) str_replace($prefix, '', $lastInvoice->invoice_number);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 6, '0', STR_PAD_LEFT);
    }
}

