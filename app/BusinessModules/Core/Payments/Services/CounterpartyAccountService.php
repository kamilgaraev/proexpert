<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceStatus;
use App\BusinessModules\Core\Payments\Models\CounterpartyAccount;
use App\BusinessModules\Core\Payments\Models\Invoice;
use Illuminate\Support\Facades\DB;

class CounterpartyAccountService
{
    /**
     * Получить или создать счёт контрагента
     */
    public function getOrCreateAccount(int $orgId, ?int $counterpartyOrgId, ?int $contractorId = null): CounterpartyAccount
    {
        return CounterpartyAccount::firstOrCreate(
            [
                'organization_id' => $orgId,
                'counterparty_organization_id' => $counterpartyOrgId,
                'counterparty_contractor_id' => $contractorId,
            ],
            [
                'receivable_balance' => 0,
                'payable_balance' => 0,
                'net_balance' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * Обновить баланс на основе счёта
     */
    public function updateBalanceFromInvoice(Invoice $invoice): void
    {
        if (!$invoice->counterparty_organization_id && !$invoice->contractor_id) {
            return;
        }

        $account = $this->getOrCreateAccount(
            $invoice->organization_id,
            $invoice->counterparty_organization_id,
            $invoice->contractor_id
        );

        $this->recalculateBalance($account);
    }

    /**
     * Пересчитать баланс контрагента
     */
    public function recalculateBalance(CounterpartyAccount $account): void
    {
        DB::transaction(function () use ($account) {
            // Дебиторка (нам должны) - incoming unpaid invoices
            $receivable = Invoice::where('organization_id', $account->organization_id)
                ->where('direction', InvoiceDirection::INCOMING)
                ->whereIn('status', [InvoiceStatus::ISSUED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::OVERDUE])
                ->where(function ($query) use ($account) {
                    $query->where('counterparty_organization_id', $account->counterparty_organization_id)
                        ->orWhere('contractor_id', $account->counterparty_contractor_id);
                })
                ->sum('remaining_amount');

            // Кредиторка (мы должны) - outgoing unpaid invoices
            $payable = Invoice::where('organization_id', $account->organization_id)
                ->where('direction', InvoiceDirection::OUTGOING)
                ->whereIn('status', [InvoiceStatus::ISSUED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::OVERDUE])
                ->where(function ($query) use ($account) {
                    $query->where('counterparty_organization_id', $account->counterparty_organization_id)
                        ->orWhere('contractor_id', $account->counterparty_contractor_id);
                })
                ->sum('remaining_amount');

            $account->update([
                'receivable_balance' => $receivable,
                'payable_balance' => $payable,
                'net_balance' => $receivable - $payable,
                'last_transaction_at' => now(),
            ]);
        });
    }

    /**
     * Выполнить взаимозачёт между организациями
     */
    public function performOffset(int $org1, int $org2): bool
    {
        $account1 = $this->getOrCreateAccount($org1, $org2);
        $account2 = $this->getOrCreateAccount($org2, $org1);

        // Пересчитать балансы
        $this->recalculateBalance($account1);
        $this->recalculateBalance($account2);

        // Определить сумму взаимозачёта
        $offsetAmount = min($account1->receivable_balance, $account2->receivable_balance);

        if ($offsetAmount <= 0) {
            return false;
        }

        // Здесь должна быть логика создания транзакций взаимозачёта
        // Пока просто логируем
        \Log::info('payments.offset.performed', [
            'org1' => $org1,
            'org2' => $org2,
            'amount' => $offsetAmount,
        ]);

        return true;
    }

    /**
     * Проверить кредитный лимит
     */
    public function checkCreditLimit(CounterpartyAccount $account, float $amount): bool
    {
        if ($account->credit_limit === null) {
            return true;
        }

        return ($account->payable_balance + $amount) <= $account->credit_limit;
    }

    /**
     * Увеличить счётчик просроченных счетов
     */
    public function incrementOverdueCount(int $counterpartyOrgId): void
    {
        CounterpartyAccount::where('counterparty_organization_id', $counterpartyOrgId)
            ->increment('overdue_invoices_count');
    }

    /**
     * Заблокировать счёт контрагента
     */
    public function blockAccount(CounterpartyAccount $account, string $reason): void
    {
        $account->update([
            'is_blocked' => true,
            'block_reason' => $reason,
        ]);

        \Log::warning('payments.counterparty.blocked', [
            'account_id' => $account->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Разблокировать счёт
     */
    public function unblockAccount(CounterpartyAccount $account): void
    {
        $account->update([
            'is_blocked' => false,
            'block_reason' => null,
        ]);
    }
}

