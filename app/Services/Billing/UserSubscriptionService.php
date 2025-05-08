<?php

namespace App\Services\Billing;

use App\Interfaces\Billing\UserSubscriptionServiceInterface;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\User;
use App\Models\Organization;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Models\Payment;
use App\DataTransferObjects\Billing\SwitchPlanResult;
use App\Exceptions\Billing\SubscriptionException;
use App\Exceptions\Billing\InsufficientBalanceException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserSubscriptionService implements UserSubscriptionServiceInterface
{
    public function __construct(
        protected PaymentGatewayInterface $paymentGateway,
        protected BalanceServiceInterface $balanceService
    ) {}

    public function subscribeUserToPlan(
        User $user,
        SubscriptionPlan $plan,
        ?string $paymentMethodToken = null,
        array $gatewayOptions = []
    ): UserSubscription {
        DB::beginTransaction();
        try {
            Log::info('[UserSubscriptionService] Attempting to get organization from user.', [
                'user_id' => $user->id,
                'current_organization_id_on_user_object' => $user->current_organization_id ?? 'NOT SET'
            ]);
            $organization = $user->currentOrganization;
            if (!$organization) {
                Log::warning('[UserSubscriptionService] $user->currentOrganization returned null.', [
                    'user_id' => $user->id,
                    'current_organization_id_on_user_object' => $user->current_organization_id ?? 'NOT SET'
                ]);
                throw new SubscriptionException('User is not associated with an organization or current organization could not be loaded.');
            }
            Log::info('[UserSubscriptionService] Organization retrieved successfully.', ['organization_id' => $organization->id]);

            $currentSubscription = $this->getUserCurrentValidSubscription($user);
            if ($currentSubscription && $currentSubscription->plan->id === $plan->id && $currentSubscription->isActive()) {
                return $currentSubscription;
            }
            if ($currentSubscription && $currentSubscription->isActive()){
                $this->cancelSubscription($currentSubscription, false);
            }

            $startsAt = Carbon::now();
            $trialEndsAt = null;
            $endsAt = $startsAt->copy()->addDays($plan->duration_in_days);
            $status = UserSubscription::STATUS_PENDING_PAYMENT;
            $paymentMadeByBalance = false;
            $paymentRecord = null;

            $amountToPay = (int) ($plan->price * 100);

            if ($amountToPay > 0) {
                if ($this->balanceService->hasSufficientBalance($organization, $amountToPay)) {
                    $this->balanceService->debitBalance(
                        $organization,
                        $amountToPay,
                        'Payment for subscription: ' . $plan->name,
                        null,
                        ['plan_slug' => $plan->slug, 'user_id' => $user->id]
                    );
                    $status = UserSubscription::STATUS_ACTIVE;
                    $paymentMadeByBalance = true;
                    Log::info("Subscription for plan {$plan->name} for user {$user->id} paid from balance.");
                } elseif ($paymentMethodToken) {
                    $chargeResponse = $this->paymentGateway->createCharge(
                        $user,
                        $amountToPay,
                        $plan->currency,
                        'Subscription to ' . $plan->name,
                        $paymentMethodToken,
                        array_merge(['subscription_plan_slug' => $plan->slug], $gatewayOptions)
                    );

                    if (!$chargeResponse->success) {
                        throw new SubscriptionException('Payment failed via gateway: ' . $chargeResponse->message);
                    }
                    $paymentRecord = Payment::create([
                        'user_id' => $user->id,
                        'payment_gateway_payment_id' => $chargeResponse->chargeId ?? 'mock_charge_' . Str::uuid(),
                        'amount' => $plan->price,
                        'currency' => $plan->currency,
                        'status' => Payment::STATUS_SUCCEEDED,
                        'description' => 'Initial payment for ' . $plan->name,
                        'paid_at' => Carbon::now(),
                    ]);
                    $status = UserSubscription::STATUS_ACTIVE;
                } else {
                    Log::info("Subscription for plan {$plan->name} for user {$user->id} is pending payment (no balance, no token).");
                }
            } elseif ($amountToPay == 0) {
                $status = UserSubscription::STATUS_ACTIVE;
            }

            $userSubscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => $status,
                'starts_at' => $status === UserSubscription::STATUS_ACTIVE ? $startsAt : null,
                'ends_at' => $status === UserSubscription::STATUS_ACTIVE ? $endsAt : null,
                'next_billing_at' => $status === UserSubscription::STATUS_ACTIVE ? $endsAt : null,
                'trial_ends_at' => $trialEndsAt,
            ]);

            if ($paymentMadeByBalance && $status === UserSubscription::STATUS_ACTIVE) {
                $paymentRecord = Payment::create([
                    'user_id' => $user->id,
                    'user_subscription_id' => $userSubscription->id,
                    'payment_gateway_payment_id' => 'balance_payment_' . Str::uuid(),
                    'amount' => $plan->price,
                    'currency' => $plan->currency,
                    'status' => Payment::STATUS_SUCCEEDED,
                    'description' => 'Paid from organization balance for: ' . $plan->name,
                    'paid_at' => Carbon::now(),
                    'payment_method_details' => ['type' => 'balance']
                ]);
                $transaction = $organization->balance->transactions()
                    ->where('description', 'Payment for subscription: ' . $plan->name)
                    ->whereNull('user_subscription_id')
                    ->latest()->first();
                if($transaction) {
                    $transaction->update(['user_subscription_id' => $userSubscription->id]);
                }
            } elseif ($paymentRecord && $status === UserSubscription::STATUS_ACTIVE) {
                $paymentRecord->update(['user_subscription_id' => $userSubscription->id]);
            }

            DB::commit();
            return $userSubscription;
        } catch (InsufficientBalanceException $e) {
            DB::rollBack();
            Log::warning('Subscription attempt failed due to insufficient balance: ' . $e->getMessage());
            throw $e; 
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Subscription failed: ' . $e->getMessage(), ['exception' => $e]);
            throw new SubscriptionException('Could not subscribe user to plan: ' . $e->getMessage());
        }
    }

    public function switchUserPlan(
        UserSubscription $currentSubscription,
        SubscriptionPlan $newPlan,
        ?string $paymentMethodToken = null
    ): SwitchPlanResult {
        DB::beginTransaction();
        try {
            $user = $currentSubscription->user;
            $organization = $user->currentOrganization;
            if (!$organization) {
                throw new SubscriptionException('User is not associated with an organization for balance operations.');
            }

            if ($currentSubscription->plan_id === $newPlan->id && $currentSubscription->isActive()) {
                throw new SubscriptionException('Cannot switch to the same plan.');
            }

            // --- Логика смены плана (упрощенная для MVP) ---
            // 1. Немедленно отменяем текущую подписку
            // В более сложной системе здесь был бы расчет прорации, возврата/кредита.
            // Пока что просто отменяем.
            $this->cancelSubscription($currentSubscription, false); // false для немедленной отмены

            // 2. Подписываем на новый план
            // Метод subscribeUserToPlan уже содержит логику оплаты (с баланса или через шлюз)
            $newlySubscribed = $this->subscribeUserToPlan($user, $newPlan, $paymentMethodToken, [
                'switched_from_plan_id' => $currentSubscription->plan_id
            ]);

            // 3. Получаем информацию о платеже, если он был
            $payment = null;
            if ($newlySubscribed->status === UserSubscription::STATUS_ACTIVE && $newPlan->price > 0) {
                // Ищем последний успешный платеж, связанный с этой новой подпиской или по описанию
                $payment = Payment::where('user_id', $user->id)
                                ->where('user_subscription_id', $newlySubscribed->id)
                                ->where('status', Payment::STATUS_SUCCEEDED)
                                ->latest('paid_at')
                                ->first();
                
                // Если платеж был с баланса, он мог быть создан без user_subscription_id изначально
                if (!$payment) {
                     $payment = Payment::where('user_id', $user->id)
                                   ->where('description', 'Paid from organization balance for: ' . $newPlan->name)
                                   ->where('status', Payment::STATUS_SUCCEEDED)
                                   ->latest('paid_at')
                                   ->first();
                }
            }
            
            $message = 'Plan switched successfully to ' . $newPlan->name . '.';
            if ($newlySubscribed->status === UserSubscription::STATUS_PENDING_PAYMENT) {
                $message = 'Plan switch to ' . $newPlan->name . ' is pending payment.';
            }

            DB::commit();
            return new SwitchPlanResult(
                newSubscription: $newlySubscribed,
                payment: $payment,
                message: $message
                // requiresAction и redirectUrl могут быть установлены, если subscribeUserToPlan вернет их
                // через исключение или измененный DTO ответа шлюза, что пока не реализовано.
            );
        } catch (InsufficientBalanceException $e) {
            DB::rollBack();
            Log::warning('Switch plan failed due to insufficient balance: ' . $e->getMessage());
            throw $e; 
        } catch (SubscriptionException $e) {
            DB::rollBack();
            Log::error('Switch plan failed: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Generic switch plan error: ' . $e->getMessage(), ['exception' => $e]);
            throw new SubscriptionException('Could not switch user plan: ' . $e->getMessage());
        }
    }

    public function cancelSubscription(UserSubscription $subscription, bool $atPeriodEnd = true): UserSubscription
    {
        if ($subscription->isCanceled() && $subscription->status !== UserSubscription::STATUS_ACTIVE) {
            return $subscription;
        }

        if ($atPeriodEnd && $subscription->isActive() && $subscription->ends_at && $subscription->ends_at->isFuture()) {
            $subscription->update([
                'canceled_at' => Carbon::now(),
            ]);
        } else {
            $subscription->update([
                'status' => UserSubscription::STATUS_CANCELED,
                'canceled_at' => Carbon::now(),
                'ends_at' => Carbon::now(),
                'next_billing_at' => null,
            ]);
        }
        return $subscription->refresh();
    }

    public function resumeSubscription(UserSubscription $subscription): UserSubscription
    {
        if (!$subscription->isCanceled() || ($subscription->ends_at && $subscription->ends_at->isFuture())) {
            throw new SubscriptionException('Subscription cannot be resumed or is already active.');
        }
        throw new SubscriptionException('Resuming subscription is not implemented yet.');
    }

    public function getUserCurrentValidSubscription(User $user): ?UserSubscription
    {
        return UserSubscription::where('user_id', $user->id)
            ->whereIn('status', [UserSubscription::STATUS_ACTIVE, UserSubscription::STATUS_TRIAL])
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function userHasActiveSubscription(User $user): bool
    {
        $subscription = $this->getUserCurrentValidSubscription($user);
        return $subscription ? $subscription->isValid() : false;
    }

    public function checkUserLimit(User $user, string $limitKey, int $valueToConsume = 1): bool
    {
        $subscription = $this->getUserCurrentValidSubscription($user);
        if (!$subscription || !$subscription->isValid()) {
            $defaultLimits = config('billing.default_limits.' . $limitKey, 0);
            return $valueToConsume <= $defaultLimits;
        }

        $planLimit = $subscription->plan->{$limitKey};

        if (is_null($planLimit)) {
            return true;
        }
        
        $currentUsage = 0;

        return ($currentUsage + $valueToConsume) <= $planLimit;
    }

    public function handleWebhook(string $eventType, array $payload): void
    {
        Log::info("Billing webhook received: {$eventType}", $payload);
        $paymentGatewayIdFromPayload = $payload['object']['id'] ?? null;
        $paymentStatus = $payload['object']['status'] ?? null;

        if (!$paymentGatewayIdFromPayload || !$paymentStatus) {
            Log::warning('Webhook ignored: missing payment_id or status.', $payload);
            return;
        }

        // Обработка платежа пополнения баланса
        $payment = Payment::where('payment_gateway_payment_id', $paymentGatewayIdFromPayload)
                        ->where('status', Payment::STATUS_PENDING)
                        ->first();

        if ($payment && str_contains(strtolower($payment->description ?? ''), 'balance top-up')) {
            if ($paymentStatus === 'succeeded') {
                DB::transaction(function () use ($payment, $payload, $paymentGatewayIdFromPayload) {
                    $payment->update([
                        'status' => Payment::STATUS_SUCCEEDED,
                        'paid_at' => Carbon::parse($payload['object']['captured_at'] ?? 'now'),
                        'gateway_response' => array_merge((array)$payment->gateway_response, ['webhook_capture' => $payload])
                    ]);

                    $user = $payment->user;
                    if ($user && $user->currentOrganization) {
                        $amountToCredit = (int) ($payload['object']['amount']['value'] * 100); 
                        $this->balanceService->creditBalance(
                            $user->currentOrganization,
                            $amountToCredit, 
                            "Balance top-up successful: {$paymentGatewayIdFromPayload}",
                            $payment,
                            ['webhook_event_id' => $payload['event_id'] ?? null]
                        );
                        Log::info("Balance top-up processed for payment_id: {$payment->id}");
                    } else {
                        Log::error("Cannot credit balance: User or Organization not found for payment_id: {$payment->id}");
                    }
                });
            } elseif (in_array($paymentStatus, ['canceled', 'failed'])) {
                $payment->update([
                    'status' => $paymentStatus === 'canceled' ? Payment::STATUS_CANCELED : Payment::STATUS_FAILED,
                    'gateway_response' => array_merge((array)$payment->gateway_response, ['webhook_capture' => $payload])
                ]);
                Log::info("Balance top-up payment {$paymentStatus}: {$payment->id}");
            }
            return; 
        }

        // Обработка платежа за подписку
        $userSubscription = UserSubscription::whereHas('payments', function ($query) use ($paymentGatewayIdFromPayload) {
            $query->where('payment_gateway_payment_id', $paymentGatewayIdFromPayload);
        })->where('status', UserSubscription::STATUS_PENDING_PAYMENT)->first();

        if ($userSubscription && $paymentStatus === 'succeeded') {
             DB::transaction(function () use ($userSubscription, $paymentGatewayIdFromPayload, $payload) {
                $associatedPayment = Payment::where('payment_gateway_payment_id', $paymentGatewayIdFromPayload)->first();
                
                if ($associatedPayment && $associatedPayment->status !== Payment::STATUS_SUCCEEDED) {
                     $associatedPayment->update([
                        'status' => Payment::STATUS_SUCCEEDED,
                        'paid_at' => Carbon::parse($payload['object']['captured_at'] ?? 'now'),
                        'gateway_response' => array_merge((array)$associatedPayment->gateway_response, ['webhook_capture' => $payload])
                    ]);
                }

                $userSubscription->update([
                    'status' => UserSubscription::STATUS_ACTIVE,
                    'starts_at' => $userSubscription->starts_at ?? Carbon::now(),
                    'ends_at' => $userSubscription->ends_at ?? Carbon::now()->addDays($userSubscription->plan->duration_in_days),
                    'next_billing_at' => $userSubscription->next_billing_at ?? Carbon::now()->addDays($userSubscription->plan->duration_in_days),
                ]);
                Log::info("Subscription activated via webhook for user_subscription_id: {$userSubscription->id}, payment_id: {$paymentGatewayIdFromPayload}");
             });
        } elseif ($userSubscription && in_array($paymentStatus, ['canceled', 'failed'])) {
            Log::warning("Subscription initial payment {$paymentStatus} for user_subscription_id: {$userSubscription->id}, payment_id: {$paymentGatewayIdFromPayload}");
        }

        // TODO: Добавить обработку других типов событий от YooKassa (например, refund.succeeded, subscription.canceled и т.д.)
    }

    public function processPastDuePayment(UserSubscription $subscription): bool
    {
        throw new SubscriptionException('Processing past due payment is not implemented yet.');
    }

    public function syncSubscriptionStatus(UserSubscription $subscription): UserSubscription
    {
        throw new SubscriptionException('Syncing subscription status is not implemented yet.');
    }
} 