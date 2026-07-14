<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialContourChange;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRenewalCycle;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

use function trans_message;

final class CommercialRenewalService
{
    private const TIMEZONE = 'Europe/Moscow';

    private const RETRY_HOUR = 3;

    private const RECONCILIATION_INTERVAL_MINUTES = 5;

    public function __construct(
        private readonly CommercialOfferCalculator $calculator,
        private readonly PaymentGatewayInterface $gateway,
        private readonly CommercialBillingNotificationService $notifications,
        private readonly CommercialWebhookProcessor $webhookProcessor,
    ) {}

    public function process(CarbonInterface $at, int $limit = 100): array
    {
        $now = CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE);
        $queryNow = $now->utc();
        if (Schema::hasTable('notifications')) {
            $this->notifications->processRenewalLifecycle($now);
        }
        $ids = OrganizationCommercialAccount::query()
            ->from('organization_commercial_accounts as accounts')
            ->leftJoin('commercial_renewal_cycles as current_cycle', function (JoinClause $join): void {
                $join->on('current_cycle.commercial_account_id', '=', 'accounts.id')
                    ->on('current_cycle.organization_id', '=', 'accounts.organization_id')
                    ->on('current_cycle.target_period_start_at', '=', 'accounts.current_period_end_at');
            })
            ->whereIn('accounts.status', ['active', 'grace'])
            ->whereNotNull('accounts.current_period_end_at')
            ->where('accounts.current_period_end_at', '<=', $queryNow)
            ->where(function ($actionable) use ($queryNow): void {
                $actionable->whereNull('current_cycle.id')
                    ->orWhere(function ($cycle) use ($queryNow): void {
                        $cycle->whereIn('current_cycle.status', ['due', 'grace'])
                            ->whereNotNull('current_cycle.next_attempt_at')
                            ->where('current_cycle.next_attempt_at', '<=', $queryNow);
                    })
                    ->orWhere(function ($expiredGrace) use ($queryNow): void {
                        $expiredGrace->where('accounts.status', 'grace')
                            ->whereNotNull('accounts.grace_ends_at')
                            ->where('accounts.grace_ends_at', '<=', $queryNow);
                    });
            })
            ->orderByRaw('COALESCE(current_cycle.next_attempt_at, accounts.current_period_end_at)')
            ->orderByRaw('CASE WHEN current_cycle.last_attempt_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('current_cycle.last_attempt_at')
            ->orderBy('accounts.id')
            ->limit(max(1, $limit))
            ->pluck('accounts.id');
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

            if ($now->lessThan($periodStart)) {
                return [];
            }

            $scheduledChange = CommercialContourChange::query()
                ->where('commercial_account_id', $account->id)
                ->where('organization_id', $account->organization_id)
                ->where('status', 'scheduled')
                ->where('apply_at', $periodStart)
                ->lockForUpdate()
                ->first();

            if ($scheduledChange !== null && $scheduledChange->target_package_slugs === []) {
                OrganizationPackageSubscription::query()
                    ->where('commercial_account_id', $account->id)
                    ->whereIn('access_source', ['paid_package', 'full_suite'])
                    ->update([
                        'status' => 'expired',
                        'current_period_end_at' => $periodStart,
                        'cancel_at' => null,
                        'canceled_at' => $periodStart,
                    ]);
                $account->forceFill([
                    'status' => 'free',
                    'offer_type' => 'packages',
                    'auto_renew_enabled' => false,
                    'saved_payment_method_active' => false,
                    'grace_started_at' => null,
                    'grace_ends_at' => null,
                ])->save();
                $scheduledChange->forceFill([
                    'status' => 'applied',
                    'applied_at' => $periodStart,
                ])->save();

                return [];
            }

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
                $currentSlugs = OrganizationPackageSubscription::query()
                    ->where('commercial_account_id', $account->id)
                    ->where('organization_id', $account->organization_id)
                    ->whereIn('access_source', ['paid_package', 'full_suite', 'corporate'])
                    ->whereIn('status', ['active', 'scheduled_for_removal'])
                    ->where('current_period_end_at', $periodStart)
                    ->orderBy('package_slug')
                    ->pluck('package_slug')
                    ->all();
                $slugs = $scheduledChange?->target_package_slugs ?? $currentSlugs;
                if ($slugs === []) {
                    return [];
                }
                $fullSuite = $scheduledChange !== null
                    ? $scheduledChange->offer_type->value === 'full_suite'
                    : $account->offer_type->value === 'full_suite';
                $quote = $this->calculator->preview($slugs, $currentSlugs, $fullSuite, $now, $account->current_period_start_at, $account->current_period_end_at);
                $key = sprintf('renewal:%d:%s', $account->id, $periodStart->toIso8601String());
                $order = CommercialOrder::query()->create([
                    'public_id' => (string) Str::uuid(), 'organization_id' => $account->organization_id,
                    'commercial_account_id' => $account->id, 'user_id' => $account->responsible_user_id,
                    'kind' => 'renewal', 'status' => 'pending_payment', 'offer_type' => $quote['offer_type'],
                    'quote_version' => $quote['quote_version'], 'selected_package_slugs' => $quote['target_package_slugs'],
                    'current_package_slugs' => $currentSlugs, 'amount_minor' => $quote['monthly_total_minor'],
                    'amount' => $quote['monthly_total'], 'currency' => $quote['currency'],
                    'period_start_at' => $periodStart, 'period_end_at' => $periodEnd, 'auto_renew_consent' => true,
                    'client_idempotency_key' => $key, 'server_idempotency_key' => $key,
                ]);
                $cycle = CommercialRenewalCycle::query()->create([
                    'organization_id' => $account->organization_id, 'commercial_account_id' => $account->id,
                    'commercial_order_id' => $order->id, 'status' => 'due', 'due_at' => $dueDay->utc(),
                    'billing_due_date' => $dueDay->toDateString(),
                    'target_period_start_at' => $periodStart, 'target_period_end_at' => $periodEnd,
                    'grace_deadline_at' => $deadline->utc(), 'attempt_count' => 0, 'next_attempt_at' => null,
                ]);
                $this->preserveAccessDuringRenewal($account, $quote['target_package_slugs'], $periodStart, $deadline);
                if ($scheduledChange !== null) {
                    $scheduledChange->forceFill([
                        'status' => 'applied',
                        'commercial_order_id' => $order->id,
                        'applied_at' => $periodStart,
                    ])->save();
                }
                $createdCycle = true;
            }
            $latest = $cycle->payments()->orderByDesc('attempt_number')->lockForUpdate()->first();
            if ($latest !== null && $latest->provider_status === 'canceled') {
                $lastDate = $latest->terminal_at?->setTimezone(self::TIMEZONE)->toDateString()
                    ?? $cycle->last_attempt_at?->setTimezone(self::TIMEZONE)->toDateString();
                $retryWindow = $now->startOfDay()->addHours(self::RETRY_HOUR);
                if ($lastDate === $now->toDateString()
                    || $cycle->attempt_count >= 7
                    || $now->lessThan($retryWindow)
                    || ($cycle->next_attempt_at !== null && $now->lessThan(CarbonImmutable::instance($cycle->next_attempt_at)))) {
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
                $cycle->forceFill([
                    'status' => 'grace',
                    'attempt_count' => $nextNumber,
                    'last_attempt_at' => $now,
                    'next_attempt_at' => $now->addMinutes(self::RECONCILIATION_INTERVAL_MINUTES)->utc(),
                ])->save();

                return ['payment' => $latest, 'created_cycles' => (int) $createdCycle, 'created_attempts' => 1];
            }
            if ($latest !== null && in_array($latest->provider_status, ['pending', 'waiting_for_capture'], true)) {
                if ($cycle->next_attempt_at !== null && $now->lessThan(CarbonImmutable::instance($cycle->next_attempt_at))) {
                    return ['created_cycles' => (int) $createdCycle];
                }
                $cycle->forceFill(['next_attempt_at' => $now->addMinutes(self::RECONCILIATION_INTERVAL_MINUTES)->utc()])->save();

                return ['reconcile_payment' => $latest, 'created_cycles' => (int) $createdCycle];
            }
            if ($latest !== null && in_array($latest->provider_status, ['succeeded', 'canceled'], true) && $latest->terminal_at === null) {
                if ($cycle->next_attempt_at !== null && $now->lessThan(CarbonImmutable::instance($cycle->next_attempt_at))) {
                    return ['created_cycles' => (int) $createdCycle];
                }
                $cycle->forceFill(['next_attempt_at' => $now->addMinutes(self::RECONCILIATION_INTERVAL_MINUTES)->utc()])->save();

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
                $cycle->forceFill([
                    'attempt_count' => 1,
                    'last_attempt_at' => $now,
                    'next_attempt_at' => $now->addMinutes(self::RECONCILIATION_INTERVAL_MINUTES)->utc(),
                ])->save();

                return ['payment' => $latest, 'created_cycles' => (int) $createdCycle, 'created_attempts' => 1];
            }

            $cycle->forceFill([
                'last_attempt_at' => $now,
                'next_attempt_at' => $now->addMinutes(self::RECONCILIATION_INTERVAL_MINUTES)->utc(),
            ])->save();

            return ['payment' => $latest, 'created_cycles' => (int) $createdCycle];
        }, 3);
    }

    private function preserveAccessDuringRenewal(
        OrganizationCommercialAccount $account,
        array $targetPackageSlugs,
        CarbonImmutable $periodStart,
        CarbonImmutable $deadline,
    ): void {
        $account->forceFill([
            'status' => 'grace',
            'grace_started_at' => $periodStart,
            'grace_ends_at' => $deadline->utc(),
        ])->save();

        OrganizationPackageSubscription::query()
            ->where('commercial_account_id', $account->id)
            ->where('organization_id', $account->organization_id)
            ->whereIn('access_source', ['paid_package', 'full_suite', 'corporate'])
            ->where('current_period_end_at', $periodStart)
            ->each(function (OrganizationPackageSubscription $subscription) use ($targetPackageSlugs, $periodStart): void {
                if (in_array($subscription->package_slug, $targetPackageSlugs, true)) {
                    $subscription->forceFill(['status' => 'grace'])->save();

                    return;
                }
                if ($subscription->access_source->value === 'corporate') {
                    return;
                }

                $subscription->forceFill([
                    'status' => 'expired',
                    'current_period_end_at' => $periodStart,
                    'cancel_at' => null,
                    'canceled_at' => $periodStart,
                ])->save();
            });
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
        OrganizationPackageSubscription::query()->where('commercial_account_id', $account->id)->where('organization_id', $account->organization_id)->whereIn('access_source', ['paid_package', 'full_suite'])->update(['status' => 'expired', 'canceled_at' => $now]);
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
