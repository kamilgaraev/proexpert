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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BalanceService implements BalanceServiceInterface
{
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

            BalanceTransaction::create([
                'organization_balance_id' => $orgBalance->id,
                'payment_id' => $payment?->id,
                'type' => BalanceTransaction::TYPE_CREDIT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $orgBalance->balance,
                'description' => $description,
                'meta' => $meta,
            ]);
            Log::info("Balance credited for organization {$organization->id}. Amount: {$amount}. New balance: {$orgBalance->balance}");
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
                
                throw new InsufficientBalanceException(
                    "Недостаточно средств на балансе организации {$organization->id} для списания {$amountRubles} руб. Текущий баланс: {$balanceRubles} руб."
                );
            }
            $balanceBefore = $orgBalance->balance;

            $orgBalance->balance -= $amount;
            $orgBalance->save();

            BalanceTransaction::create([
                'organization_balance_id' => $orgBalance->id,
                'organization_subscription_id' => $subscription?->id,
                'type' => BalanceTransaction::TYPE_DEBIT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $orgBalance->balance,
                'description' => $description,
                'meta' => $meta,
            ]);
            Log::info("Balance debited for organization {$organization->id}. Amount: {$amount}. New balance: {$orgBalance->balance}");
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
 