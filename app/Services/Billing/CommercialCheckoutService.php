<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\Enums\Billing\CommercialOrderStatus;
use App\Exceptions\Billing\CommercialCheckoutAmountException;
use App\Exceptions\Billing\CommercialCheckoutConflictException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

use function trans_message;

class CommercialCheckoutService
{
    public function __construct(
        private readonly CommercialOfferCalculator $calculator,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    public function checkout(Organization $organization, User $user, array $input): array
    {
        [$order, $payment, $created] = DB::transaction(function () use ($organization, $user, $input): array {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            $existing = CommercialOrder::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_idempotency_key', (string) $input['client_idempotency_key'])
                ->with('latestPayment')
                ->first();

            if ($existing !== null) {
                $this->assertSameExistingRequest($existing, $input);

                return [$existing, $existing->latestPayment, false];
            }

            if ((bool) $input['full_suite'] && $input['target_package_slugs'] !== []) {
                throw new InvalidArgumentException('Full suite checkout must not contain target packages.');
            }

            $this->calculator->assertCurrentQuoteVersion((int) $input['quote_version']);

            $account = OrganizationCommercialAccount::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();

            if ($account?->status->value === 'grace') {
                throw new CommercialCheckoutConflictException('Commercial contour cannot change during grace.');
            }
            $serverCurrent = $this->currentPackageSlugs((int) $organization->getKey());
            $clientCurrent = $this->normalizeClientSlugs($input['current_package_slugs'] ?? []);

            if ($clientCurrent !== $serverCurrent) {
                throw new CommercialCheckoutConflictException('Current commercial contour has changed.');
            }

            $quote = $this->calculator->preview(
                $input['target_package_slugs'],
                $serverCurrent,
                (bool) $input['full_suite'],
                currentPeriodStartAt: $account?->current_period_start_at,
                currentPeriodEndAt: $account?->current_period_end_at,
            );

            if ((int) $quote['amount_due_now_minor'] <= 0) {
                throw new CommercialCheckoutAmountException('Payment checkout requires a positive amount.');
            }

            $account ??= OrganizationCommercialAccount::query()->create([
                'organization_id' => $organization->getKey(),
                'responsible_user_id' => $user->getKey(),
                'status' => 'free',
                'offer_type' => 'packages',
                'quote_version' => (int) $quote['quote_version'],
                'auto_renew_enabled' => false,
            ]);

            $order = CommercialOrder::query()->create([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $organization->getKey(),
                'commercial_account_id' => $account->getKey(),
                'user_id' => $user->getKey(),
                'status' => CommercialOrderStatus::PendingPayment,
                'offer_type' => $quote['offer_type'],
                'quote_version' => $quote['quote_version'],
                'selected_package_slugs' => $quote['target_package_slugs'],
                'current_package_slugs' => $quote['current_package_slugs'],
                'amount_minor' => $quote['amount_due_now_minor'],
                'amount' => $quote['amount_due_now'],
                'currency' => $quote['currency'],
                'period_start_at' => $quote['period_start_at'],
                'period_end_at' => $quote['period_end_at'],
                'auto_renew_consent' => (bool) $input['auto_renew_consent'],
                'client_idempotency_key' => (string) $input['client_idempotency_key'],
            ]);
            $payment = $order->payments()->create([
                'provider' => 'yookassa',
                'role' => 'initial',
                'attempt_number' => 1,
                'provider_status' => 'created',
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'provider_idempotency_key' => (string) Str::uuid(),
                'payment_method_saved' => false,
            ]);

            return [$order, $payment, true];
        }, 3);

        if ($payment->provider_payment_id === null) {
            $result = $this->gateway->createPayment(new CreatePaymentData(
                idempotenceKey: $payment->provider_idempotency_key,
                amountMinor: $order->amount_minor,
                currency: $order->currency,
                description: trans_message('billing.checkout.payment_description'),
                metadata: [
                    'order_id' => $order->public_id,
                    'organization_id' => $order->organization_id,
                ],
                savePaymentMethod: $order->auto_renew_consent,
            ));

            DB::transaction(function () use ($payment, $result): void {
                $current = CommercialPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if ($current->provider_payment_id === null) {
                    $current->forceFill([
                        'provider_payment_id' => $result->id,
                        'provider_status' => $result->status,
                        'confirmation_url' => $result->confirmationUrl,
                        'payment_method_id' => $result->paymentMethodId,
                        'payment_method_saved' => $result->paymentMethodSaved,
                        'safe_response' => $result->safeResponse,
                    ])->save();

                    return;
                }

                if ($current->provider_payment_id !== $result->id) {
                    throw new CommercialCheckoutConflictException('Payment intent is already bound to another provider payment.');
                }
            }, 3);
        }

        return $this->response($order->fresh(), $payment->fresh()) + ['_created' => $created];
    }

    private function currentPackageSlugs(int $organizationId): array
    {
        $slugs = OrganizationPackageSubscription::query()
            ->where('organization_id', $organizationId)
            ->whereIn('access_source', ['paid_package', 'full_suite', 'corporate'])
            ->active()
            ->pluck('package_slug')
            ->all();

        sort($slugs);

        return array_values($slugs);
    }

    private function normalizeClientSlugs(array $slugs): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $slug): string => trim((string) $slug),
            $slugs,
        )));
        sort($normalized);

        return $normalized;
    }

    private function assertSameExistingRequest(CommercialOrder $order, array $input): void
    {
        $requestedFullSuite = (bool) $input['full_suite'];
        $requestedTargets = $this->normalizeClientSlugs($input['target_package_slugs']);
        $sameTarget = $requestedFullSuite
            ? $requestedTargets === []
            : $requestedTargets
                === $this->normalizeClientSlugs($order->selected_package_slugs);
        $sameCurrent = $this->normalizeClientSlugs($input['current_package_slugs'] ?? [])
            === $this->normalizeClientSlugs($order->current_package_slugs);

        if (! $sameTarget
            || ! $sameCurrent
            || ($order->offer_type->value === 'full_suite') !== $requestedFullSuite
            || $order->quote_version !== (int) $input['quote_version']
            || $order->auto_renew_consent !== (bool) $input['auto_renew_consent']) {
            throw new CommercialCheckoutConflictException('Idempotency key belongs to another checkout request.');
        }
    }

    private function response(CommercialOrder $order, CommercialPayment $payment): array
    {
        return [
            'order_id' => $order->public_id,
            'status' => $order->status->value,
            'amount' => $order->amount,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'confirmation_url' => $payment->confirmation_url,
            'payment_status' => $payment->provider_status,
            'auto_renew_consent' => $order->auto_renew_consent,
        ];
    }
}
