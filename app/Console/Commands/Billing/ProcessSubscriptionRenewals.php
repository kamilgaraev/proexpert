<?php

namespace App\Console\Commands\Billing;

use Illuminate\Console\Command;
use App\Models\UserSubscription;
use App\Models\SubscriptionPlan;
use App\Models\Payment;
use App\Interfaces\Billing\UserSubscriptionServiceInterface;
use App\Interfaces\Billing\BalanceServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessSubscriptionRenewals extends Command
{
    protected $signature = 'billing:process-renewals';
    protected $description = 'Processes active subscriptions due for renewal.';

    protected UserSubscriptionServiceInterface $subscriptionService;
    protected BalanceServiceInterface $balanceService;

    public function __construct(UserSubscriptionServiceInterface $subscriptionService, BalanceServiceInterface $balanceService)
    {
        parent::__construct();
        $this->subscriptionService = $subscriptionService;
        $this->balanceService = $balanceService;
    }

    public function handle(): int
    {
        $this->info('Starting subscription renewal process...');
        $now = Carbon::now();

        // Выбираем подписки, которые должны быть продлены
        // (next_billing_at наступил или немного в прошлом, и они активны и не отменены)
        $subscriptionsToRenew = UserSubscription::where('status', UserSubscription::STATUS_ACTIVE)
            ->where('is_auto_payment_enabled', true)
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<= ', $now)
            ->whereNull('canceled_at') // Убедимся, что подписка не отменена к концу периода
            ->with(['user.organization', 'plan']) // Загружаем связи для эффективности
            ->get();

        if ($subscriptionsToRenew->isEmpty()) {
            $this->info('No subscriptions due for renewal at this time.');
            return Command::SUCCESS;
        }

        $this->info("Found {$subscriptionsToRenew->count()} subscriptions to process.");

        foreach ($subscriptionsToRenew as $subscription) {
            $this->processSingleSubscription($subscription, $now);
        }

        $this->info('Subscription renewal process finished.');
        return Command::SUCCESS;
    }

    protected function processSingleSubscription(UserSubscription $subscription, Carbon $now): void
    {
        $user = $subscription->user;
        $plan = $subscription->plan;
        $organization = $user->organization; // Предполагаем, что связь user->organization существует

        if (!$organization) {
            Log::error("Renewal failed for subscription {$subscription->id}: User {$user->id} has no organization.");
            $this->error("Skipping subscription {$subscription->id}: User has no organization.");
            // Можно установить статус подписки в past_due или error
            // $subscription->update(['status' => UserSubscription::STATUS_PAST_DUE, 'next_billing_at' => null]); 
            return;
        }

        $this->info("Processing subscription ID: {$subscription->id} for user {$user->id} on plan '{$plan->name}'.");

        $amountToPay = (int) ($plan->price * 100);

        if ($amountToPay <= 0) { // Бесплатный план или план с нулевой стоимостью
            $this->renewSubscriptionRecord($subscription, $plan, $now);
            Log::info("Subscription ID: {$subscription->id} (free plan) renewed successfully.");
            $this->info("Subscription ID: {$subscription->id} (free plan) renewed.");
            // TODO: Отправить уведомление о продлении бесплатной подписки, если нужно
            return;
        }

        try {
            if ($this->balanceService->hasSufficientBalance($organization, $amountToPay)) {
                $this->balanceService->debitBalance(
                    $organization,
                    $amountToPay,
                    "Renewal payment for subscription: {$plan->name} (ID: {$subscription->id})",
                    $subscription, // Связываем транзакцию с подпиской
                    ['renewal_attempt' => $now->toDateTimeString()]
                );
                
                // Создаем запись о платеже из баланса
                Payment::create([
                    'user_id' => $user->id,
                    'user_subscription_id' => $subscription->id,
                    'payment_gateway_payment_id' => 'balance_renewal_' . Str::uuid(),
                    'amount' => $plan->price,
                    'currency' => $plan->currency,
                    'status' => Payment::STATUS_SUCCEEDED,
                    'description' => "Renewal paid from balance for: {$plan->name} (ID: {$subscription->id})",
                    'paid_at' => $now,
                    'payment_method_details' => ['type' => 'balance']
                ]);

                $this->renewSubscriptionRecord($subscription, $plan, $now);
                Log::info("Subscription ID: {$subscription->id} renewed successfully from balance.");
                $this->info("Subscription ID: {$subscription->id} renewed from balance.");
                // TODO: Отправить уведомление об успешном продлении
            } else {
                // Баланса недостаточно. 
                // TODO: Попытка списания через сохраненный метод оплаты шлюза (если будет реализовано)
                
                $subscription->update(['status' => UserSubscription::STATUS_PAST_DUE]);
                Log::warning("Subscription ID: {$subscription->id} renewal failed: Insufficient balance. Status set to PAST_DUE.");
                $this->warn("Subscription ID: {$subscription->id} - Insufficient balance. Status set to PAST_DUE.");
                // TODO: Отправить уведомление пользователю о необходимости пополнить баланс / обновить платежные данные
            }
        } catch (\Exception $e) {
            Log::error("Error renewing subscription ID: {$subscription->id}. Error: " . $e->getMessage(), ['exception' => $e]);
            $this->error("Error processing subscription ID: {$subscription->id}. Check logs.");
            // Можно установить статус PAST_DUE, чтобы следующая попытка была позже
             $subscription->update(['status' => UserSubscription::STATUS_PAST_DUE, 'next_billing_at' => $now->addHours(6)]); // Попробовать через 6 часов
        }
    }

    protected function renewSubscriptionRecord(UserSubscription $subscription, SubscriptionPlan $plan, Carbon $now): void
    {
        $newEndsAt = $now->copy()->addDays($plan->duration_in_days);
        $subscription->update([
            'starts_at' => $now, // Обновляем дату начала текущего периода
            'ends_at' => $newEndsAt,
            'next_billing_at' => $newEndsAt, // Следующее списание будет после окончания нового периода
            'status' => UserSubscription::STATUS_ACTIVE, // Убедимся, что статус активен
        ]);
    }
} 