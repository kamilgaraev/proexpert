<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\Enums\Billing\PaymentProviderMode;
use App\Exceptions\Billing\CommercialCheckoutConflictException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRenewalCycle;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class CommercialManualPaymentService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly CommercialPaymentProviderPolicy $providerPolicy,
    ) {}

    public function create(Organization $organization, User $user, string $idempotencyKey): array
    {
        $this->providerPolicy->assertCanCharge((int) $organization->getKey());

        [$order, $payment, $created] = DB::transaction(function () use (
            $organization,
            $idempotencyKey,
        ): array {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $account = OrganizationCommercialAccount::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();

            if ($account === null || $account->status->value !== 'grace') {
                throw new CommercialCheckoutConflictException('Manual payment requires an active grace period.');
            }

            $cycle = CommercialRenewalCycle::query()
                ->where('organization_id', $organization->getKey())
                ->where('commercial_account_id', $account->getKey())
                ->whereIn('status', ['grace', 'disabled'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($cycle === null || now()->greaterThanOrEqualTo($cycle->grace_deadline_at)) {
                throw new CommercialCheckoutConflictException('Manual payment grace deadline has passed.');
            }

            $order = CommercialOrder::query()
                ->whereKey($cycle->commercial_order_id)
                ->where('organization_id', $organization->getKey())
                ->where('commercial_account_id', $account->getKey())
                ->where('kind', 'renewal')
                ->where('status', 'pending_payment')
                ->lockForUpdate()
                ->first();

            if ($order === null
                || ! $order->period_start_at?->equalTo($cycle->target_period_start_at)
                || ! $order->period_end_at?->equalTo($cycle->target_period_end_at)) {
                throw new CommercialCheckoutConflictException('Renewal snapshot is unavailable for manual payment.');
            }

            $existing = CommercialPayment::query()
                ->where('provider_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->commercial_order_id !== $order->getKey()
                    || $existing->commercial_renewal_cycle_id !== $cycle->getKey()
                    || $existing->role !== 'renewal'
                    || $existing->amount_minor !== $order->amount_minor
                    || $existing->currency !== $order->currency) {
                    throw new CommercialCheckoutConflictException('Manual payment key belongs to another intent.');
                }

                return [$order, $existing, false];
            }

            $attemptNumber = ((int) $order->payments()->max('attempt_number')) + 1;
            $payment = $order->payments()->create([
                'commercial_renewal_cycle_id' => $cycle->getKey(),
                'role' => 'renewal',
                'attempt_number' => $attemptNumber,
                'provider' => 'yookassa',
                'provider_status' => 'created',
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'provider_idempotency_key' => $idempotencyKey,
                'payment_method_saved' => false,
            ]);
            $cycle->forceFill([
                'attempt_count' => max($cycle->attempt_count, $attemptNumber),
                'last_attempt_at' => now(),
                'next_attempt_at' => now()->addMinutes(10),
            ])->save();

            return [$order, $payment, true];
        }, 3);

        if ($payment->provider_payment_id === null) {
            $result = $this->gateway->createPayment(new CreatePaymentData(
                idempotenceKey: $payment->provider_idempotency_key,
                amountMinor: $order->amount_minor,
                currency: $order->currency,
                description: trans_message('billing.renewal.manual_payment_description'),
                metadata: [
                    'order_id' => $order->public_id,
                    'organization_id' => $order->organization_id,
                    'manual_payment_key' => $payment->provider_idempotency_key,
                ],
                savePaymentMethod: false,
                customerEmail: $user->email,
            ));

            DB::transaction(function () use ($payment, $result): void {
                $current = CommercialPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
                if ($current->provider_payment_id === null) {
                    $current->forceFill([
                        'provider_payment_id' => $result->id,
                        'provider_status' => $result->status,
                        'confirmation_url' => $result->confirmationUrl,
                        'payment_method_id' => null,
                        'payment_method_saved' => false,
                        'safe_response' => $result->safeResponse,
                        'attempted_at' => now(),
                    ])->save();
                } elseif ($current->provider_payment_id !== $result->id) {
                    throw new CommercialCheckoutConflictException('Manual payment is bound to another provider payment.');
                }
            }, 3);
        }

        $payment = $payment->fresh();

        return [
            'order_id' => $order->public_id,
            'status' => $order->status->value,
            'payment_status' => $payment->provider_status,
            'amount' => $order->amount,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'confirmation_url' => $payment->confirmation_url,
            'selected_package_slugs' => $order->selected_package_slugs,
            'period_start_at' => $order->period_start_at?->toJSON(),
            'period_end_at' => $order->period_end_at?->toJSON(),
            'grace_deadline_at' => $order->renewalCycle?->grace_deadline_at?->toJSON(),
            'test_mode' => PaymentProviderMode::configured()->testMode(),
            '_created' => $created,
        ];
    }
}
