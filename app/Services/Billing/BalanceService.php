<?php

namespace App\Services\Billing;

use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\Organization;
use App\Models\OrganizationBalance;
use App\Models\BalanceTransaction;
use App\Models\Payment;
use App\Models\OrganizationSubscription;
use App\Exceptions\Billing\BalanceException;
use App\Exceptions\Billing\InsufficientBalanceException;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BalanceService implements BalanceServiceInterface
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    public function getOrCreateOrganizationBalance(Organization $organization): OrganizationBalance
    {
        return OrganizationBalance::firstOrCreate(
            ['organization_id' => $organization->id],
            ['currency' => 'RUB', 'balance' => 0] // Default values if creating
        );
    }

    public function creditBalance(
        Organization $organization,
        int $amount,
        string $description,
        ?Payment $payment = null,
        array $meta = []
    ): OrganizationBalance {
        if ($amount <= 0) {
            throw new BalanceException('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($organization, $amount, $description, $payment, $meta) {
            $orgBalance = $this->getOrCreateOrganizationBalance($organization);
            $balanceBefore = $orgBalance->balance;
            
            $orgBalance->balance += $amount;
            $orgBalance->save();

            $transaction = BalanceTransaction::create([
                'organization_balance_id' => $orgBalance->id,
                'payment_id' => $payment?->id,
                'type' => BalanceTransaction::TYPE_CREDIT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $orgBalance->balance,
                'description' => $description,
                'meta' => $meta,
            ]);

            // BUSINESS: Пополнение баланса - критически важная SaaS метрика
            $this->logging->business('billing.balance.credited', [
                'organization_id' => $organization->id,
                'transaction_id' => $transaction->id,
                'payment_id' => $payment?->id,
                'amount_cents' => $amount,
                'amount_rubles' => round($amount / 100, 2),
                'balance_before_cents' => $balanceBefore,
                'balance_after_cents' => $orgBalance->balance,
                'balance_after_rubles' => round($orgBalance->balance / 100, 2),
                'description' => $description,
                'currency' => $orgBalance->currency,
                'meta' => $meta
            ]);

            // AUDIT: Финансовая операция для compliance и аудита
            $this->logging->audit('billing.transaction.credit', [
                'organization_id' => $organization->id,
                'transaction_id' => $transaction->id,
                'transaction_type' => 'credit',
                'amount_cents' => $amount,
                'balance_change_cents' => $amount,
                'performed_by' => request()->user()?->id ?? 'system',
                'description' => $description,
                'payment_reference' => $payment?->id
            ]);

            return $orgBalance->refresh();
        });
    }

    public function debitBalance(
        Organization $organization,
        int $amount,
        string $description,
        ?OrganizationSubscription $subscription = null,
        array $meta = []
    ): OrganizationBalance {
        if ($amount <= 0) {
            throw new BalanceException('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($organization, $amount, $description, $subscription, $meta) {
            $orgBalance = $this->getOrCreateOrganizationBalance($organization);
            
            if ($orgBalance->balance < $amount) {
                $amountRubles = number_format($amount / 100, 2, '.', '');
                $balanceRubles = number_format($orgBalance->balance / 100, 2, '.', '');
                
                // SECURITY: Попытка списания при недостаточном балансе - критично для fraud detection
                $this->logging->security('billing.insufficient_balance.attempt', [
                    'organization_id' => $organization->id,
                    'attempted_debit_cents' => $amount,
                    'attempted_debit_rubles' => $amountRubles,
                    'current_balance_cents' => $orgBalance->balance,
                    'current_balance_rubles' => $balanceRubles,
                    'deficit_cents' => $amount - $orgBalance->balance,
                    'deficit_rubles' => round(($amount - $orgBalance->balance) / 100, 2),
                    'description' => $description,
                    'user_id' => request()->user()?->id,
                    'subscription_id' => $subscription?->id,
                    'potential_fraud' => $amount > ($orgBalance->balance * 10) // Флаг если попытка списать в 10+ раз больше баланса
                ], 'warning');
                
                throw new InsufficientBalanceException(
                    "Недостаточно средств на балансе организации {$organization->id} для списания {$amountRubles} руб. Текущий баланс: {$balanceRubles} руб."
                );
            }
            $balanceBefore = $orgBalance->balance;

            $orgBalance->balance -= $amount;
            $orgBalance->save();

            $transaction = BalanceTransaction::create([
                'organization_balance_id' => $orgBalance->id,
                'organization_subscription_id' => $subscription?->id,
                'type' => BalanceTransaction::TYPE_DEBIT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $orgBalance->balance,
                'description' => $description,
                'meta' => $meta,
            ]);

            // BUSINESS: Списание с баланса - критически важная SaaS метрика для revenue tracking
            $this->logging->business('billing.balance.debited', [
                'organization_id' => $organization->id,
                'transaction_id' => $transaction->id,
                'subscription_id' => $subscription?->id,
                'amount_cents' => $amount,
                'amount_rubles' => round($amount / 100, 2),
                'balance_before_cents' => $balanceBefore,
                'balance_after_cents' => $orgBalance->balance,
                'balance_after_rubles' => round($orgBalance->balance / 100, 2),
                'balance_change_cents' => -$amount,
                'description' => $description,
                'currency' => $orgBalance->currency,
                'meta' => $meta,
                'is_subscription_payment' => $subscription !== null
            ]);

            // AUDIT: Финансовая операция для compliance и аудита
            $this->logging->audit('billing.transaction.debit', [
                'organization_id' => $organization->id,
                'transaction_id' => $transaction->id,
                'transaction_type' => 'debit',
                'amount_cents' => $amount,
                'balance_change_cents' => -$amount,
                'subscription_reference' => $subscription?->id,
                'performed_by' => request()->user()?->id ?? 'system',
                'description' => $description
            ]);

            return $orgBalance->refresh();
        });
    }

    public function hasSufficientBalance(Organization $organization, int $amount): bool
    {
        if ($amount < 0) return true; // Cannot debit a negative amount, consider balance sufficient
        if ($amount == 0) return true;
        
        $orgBalance = $this->getOrCreateOrganizationBalance($organization);
        return $orgBalance->balance >= $amount;
    }
}
 