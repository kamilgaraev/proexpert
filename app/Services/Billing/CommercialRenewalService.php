<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRenewalCycle;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

use function trans_message;

final class CommercialRenewalService
{
    private const TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly CommercialOfferCalculator $calculator,
        private readonly PaymentGatewayInterface $gateway,
        private readonly CommercialBillingNotificationService $notifications,
        private readonly CommercialWebhookProcessor $webhookProcessor,
    ) {}

    public function process(CarbonInterface $at, int $limit = 100): array
    {
        $now = CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE);
        if (Schema::hasTable('notifications')) {
            $this->notifications->processRenewalLifecycle($now);
        }
        $ids = OrganizationCommercialAccount::query()
            ->whereIn('status', ['active', 'grace'])
            ->whereNotNull('current_period_end_at')
            ->where('current_period_end_at', '<=', $now->endOfDay())
            ->orderBy('id')->limit(max(1, $limit))->pluck('id');
        $counts = ['processed' => 0, 'created_cycles' => 0, 'created_attempts' => 0, 'failed' => 0, 'suspended' => 0];

        foreach ($ids as $id) {
            try {
                $phase = $this->prepare((int) $id, $now);
                $counts['processed']++;
                foreach (['created_cycles', 'created_attempts', 'suspended'] as $key) {
                    $counts[$key] += (int) ($phase[$key] ?? 0);
                }
                if (($phase['payment'] ?? null) instanceof CommercialPayment) {
                    $this->send($phase['payment']);
                } elseif (($phase['reconcile_payment'] ?? null) instanceof CommercialPayment) {
                    $this->reconcile($phase['reconcile_payment']);
                }
            } catch (Throwable $exception) {
                $counts['failed']++;
                Log::error('Commercial renewal account processing failed.', [
                    'commercial_account_id' => (int) $id,
                    'exception' => $exception::class,
                    'code' => $exception->getCode(),
                ]);
            }
        }

        if (Schema::hasTable('notifications')) {
            $this->notifications->processRenewalLifecycle($now);
        }

        return $counts;
    }

    private function prepare(int $accountId, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($accountId, $now): array {
            $account = OrganizationCommercialAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $periodStart = CarbonImmutable::instance($account->current_period_end_at);
            $periodEnd = $periodStart->addDays(30);
            $dueDay = $periodStart->setTimezone(self::TIMEZONE)->startOfDay();
            $deadline = $dueDay->addDays(7);

            if ($now->startOfDay()->greaterThanOrEqualTo($deadline)) {
                $this->suspend($account, $now);

                return ['suspended' => 1];
            }

            if (! $account->auto_renew_enabled || ! $account->saved_payment_method_active || trim((string) $account->saved_payment_method_id) === '') {
                if ($account->status->value === 'grace') {
                    return [];
                }
                if ($now->greaterThanOrEqualTo($periodStart)) {
                    $this->suspend($account, $now);

                    return ['suspended' => 1];
                }

                return [];
            }
            if ($now->startOfDay()->lessThan($dueDay)) {
                return [];
            }
            $cycle = CommercialRenewalCycle::query()->where('commercial_account_id', $account->id)->where('target_period_start_at', $periodStart)->lockForUpdate()->first();
            $createdCycle = false;
            if ($cycle === null) {
                $slugs = OrganizationPackageSubscription::query()->where('commercial_account_id', $account->id)->whereIn('access_source', ['paid_package', 'full_suite'])->active()->orderBy('package_slug')->pluck('package_slug')->all();
                if ($slugs === []) {
                    return [];
                }
                $fullSuite = $account->offer_type->value === 'full_suite';
                $quote = $this->calculator->preview($slugs, $slugs, $fullSuite, $now, $account->current_period_start_at, $account->current_period_end_at);
                $key = sprintf('renewal:%d:%s', $account->id, $periodStart->toIso8601String());
                $order = CommercialOrder::query()->create([
                    'public_id' => (string) Str::uuid(), 'organization_id' => $account->organization_id,
                    'commercial_account_id' => $account->id, 'user_id' => $account->responsible_user_id,
                    'kind' => 'renewal', 'status' => 'pending_payment', 'offer_type' => $account->offer_type->value,
                    'quote_version' => $quote['quote_version'], 'selected_package_slugs' => $quote['target_package_slugs'],
                    'current_package_slugs' => $slugs, 'amount_minor' => $quote['monthly_total_minor'],
                    'amount' => $quote['monthly_total'], 'currency' => $quote['currency'],
                    'period_start_at' => $periodStart, 'period_end_at' => $periodEnd, 'auto_renew_consent' => true,
                    'client_idempotency_key' => $key, 'server_idempotency_key' => $key,
                ]);
                $cycle = CommercialRenewalCycle::query()->create([
                    'organization_id' => $account->organization_id, 'commercial_account_id' => $account->id,
                    'commercial_order_id' => $order->id, 'status' => 'due', 'due_at' => $dueDay->utc(),
                    'billing_due_date' => $dueDay->toDateString(),
                    'target_period_start_at' => $periodStart, 'target_period_end_at' => $periodEnd,
                    'grace_deadline_at' => $deadline->utc(), 'attempt_count' => 0, 'next_attempt_at' => $dueDay->addHours(3)->utc(),
                ]);
                $createdCycle = true;
            }
            $latest = $cycle->payments()->orderByDesc('attempt_number')->lockForUpdate()->first();
            if ($latest !== null && $latest->provider_status === 'canceled') {
                $lastDate = $latest->terminal_at?->setTimezone(self::TIMEZONE)->toDateString()
                    ?? $cycle->last_attempt_at?->setTimezone(self::TIMEZONE)->toDateString();
                if ($lastDate === $now->toDateString() || $cycle->attempt_count >= 7) {
                    return ['created_cycles' => (int) $createdCycle];
                }
                $order = $cycle->order;
                $nextNumber = $cycle->attempt_count + 1;
                $latest = $cycle->payments()->create([
                    'commercial_order_id' => $order->id, 'role' => 'renewal', 'attempt_number' => $nextNumber,
                    'provider' => 'yookassa', 'provider_status' => 'created', 'amount_minor' => $order->amount_minor,
                    'currency' => $order->currency, 'provider_idempotency_key' => (string) Str::uuid(),
                    'payment_method_saved' => false,
                ]);
                $cycle->forceFill(['status' => 'grace', 'attempt_count' => $nextNumber, 'last_attempt_at' => $now, 'next_attempt_at' => $now->addDay()->startOfDay()->addHours(3)])->save();

                return ['payment' => $latest, 'created_cycles' => (int) $createdCycle, 'created_attempts' => 1];
            }
            if ($latest !== null && in_array($latest->provider_status, ['pending', 'waiting_for_capture'], true)) {
                return ['reconcile_payment' => $latest, 'created_cycles' => (int) $createdCycle];
            }
            if ($latest !== null && in_array($latest->provider_status, ['succeeded', 'canceled'], true) && $latest->terminal_at === null) {
                return ['reconcile_payment' => $latest, 'created_cycles' => (int) $createdCycle];
            }
            if ($latest !== null && $latest->provider_status !== 'created') {
                return ['created_cycles' => (int) $createdCycle];
            }
            if ($latest === null) {
                $order = $cycle->order;
                $latest = $cycle->payments()->create([
                    'commercial_order_id' => $order->id, 'role' => 'renewal', 'attempt_number' => 1,
                    'provider' => 'yookassa', 'provider_status' => 'created', 'amount_minor' => $order->amount_minor,
                    'currency' => $order->currency, 'provider_idempotency_key' => (string) Str::uuid(),
                    'payment_method_saved' => false,
                ]);
                $cycle->forceFill(['attempt_count' => 1, 'last_attempt_at' => $now])->save();

                return ['payment' => $latest, 'created_cycles' => (int) $createdCycle, 'created_attempts' => 1];
            }

            return ['payment' => $latest, 'created_cycles' => (int) $createdCycle];
        }, 3);
    }

    private function send(CommercialPayment $payment): void
    {
        $payment->loadMissing('order.commercialAccount');
        $order = $payment->order;
        $account = $order->commercialAccount;
        $result = $this->gateway->createSavedMethodPayment(new CreateSavedMethodPaymentData(
            $payment->provider_idempotency_key, $payment->amount_minor, $payment->currency,
            (string) $account->saved_payment_method_id, trans_message('billing.renewal.payment_description'),
            ['order_id' => $order->public_id, 'organization_id' => $order->organization_id],
        ));
        DB::transaction(function () use ($payment, $result): void {
            $current = CommercialPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($current->provider_status === 'succeeded') {
                return;
            }
            if ($current->provider_payment_id !== null && $current->provider_payment_id !== $result->id) {
                return;
            }
            $current->forceFill(['provider_payment_id' => $result->id, 'provider_status' => $result->status, 'safe_response' => $result->safeResponse, 'attempted_at' => now()])->save();
        }, 3);
        if (in_array($result->status, ['succeeded', 'canceled'], true)) {
            $this->reconcile(CommercialPayment::query()->findOrFail($payment->id));
        }
    }

    private function suspend(OrganizationCommercialAccount $account, CarbonImmutable $now): void
    {
        $account->forceFill(['status' => 'suspended'])->save();
        OrganizationPackageSubscription::query()->where('commercial_account_id', $account->id)->whereIn('access_source', ['paid_package', 'full_suite'])->update(['status' => 'expired', 'canceled_at' => $now]);
        CommercialRenewalCycle::query()->where('commercial_account_id', $account->id)->whereIn('status', ['due', 'grace'])->update(['status' => 'suspended', 'suspended_at' => $now]);
    }

    private function reconcile(CommercialPayment $payment): void
    {
        if ($payment->provider_payment_id === null) {
            return;
        }
        $result = $this->gateway->getPayment($payment->provider_payment_id);
        $event = match ($result->status) {
            'succeeded' => 'payment.succeeded',
            'canceled' => 'payment.canceled',
            'waiting_for_capture' => 'payment.waiting_for_capture',
            default => null,
        };
        if ($event === null) {
            return;
        }
        $this->webhookProcessor->process(new YooKassaWebhookNotification(
            $event,
            $result->id,
            $result->status,
            ['type' => 'notification', 'event' => $event, 'object' => ['id' => $result->id, 'status' => $result->status]],
        ), '127.0.0.1');
    }
}
