<?php

declare(strict_types=1);

namespace App\Services\Contractor;

use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\ContractorInvitation;
use App\Models\ContractorReferralReward;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ContractorReferralRewardService
{
    public function __construct(private readonly BalanceServiceInterface $balanceService) {}

    public function handleFirstPaidOrder(
        CommercialOrder $order,
        CommercialPayment $payment,
    ): ?ContractorReferralReward {
        if (! $this->isEligiblePayment($order, $payment) || ! $this->hasRequiredTables()) {
            return null;
        }

        $firstPaidOrderId = CommercialOrder::query()
            ->where('organization_id', $order->organization_id)
            ->where('kind', 'purchase')
            ->where('status', 'paid')
            ->orderBy('id')
            ->value('id');
        if ((int) $firstPaidOrderId !== $order->id
            || ContractorReferralReward::query()->where('invited_organization_id', $order->organization_id)->exists()) {
            return null;
        }

        $invitation = ContractorInvitation::query()
            ->where('invited_organization_id', $order->organization_id)
            ->where('status', ContractorInvitation::STATUS_ACCEPTED)
            ->latest('accepted_at')
            ->first();
        if ($invitation === null) {
            return null;
        }

        return DB::transaction(function () use ($order, $payment, $invitation): ContractorReferralReward {
            $amounts = $this->calculateRewardAmounts($payment->amount_minor);
            $fraudReason = $this->detectAntiFraudReason($invitation);

            return ContractorReferralReward::query()->create([
                'contractor_invitation_id' => $invitation->id,
                'inviting_organization_id' => $invitation->organization_id,
                'invited_organization_id' => $invitation->invited_organization_id,
                'commercial_order_id' => $order->id,
                'commercial_payment_id' => $payment->id,
                'status' => $fraudReason === null ? ContractorReferralReward::STATUS_PENDING : ContractorReferralReward::STATUS_CANCELLED,
                'first_payment_amount' => $payment->amount_minor,
                'inviting_reward_amount' => $amounts['inviting_reward_amount'],
                'invited_welcome_amount' => $amounts['invited_welcome_amount'],
                'currency' => $payment->currency,
                'eligible_at' => $order->period_end_at,
                'cancelled_at' => $fraudReason === null ? null : now(),
                'cancellation_reason' => $fraudReason,
                'meta' => [
                    'offer_type' => $order->offer_type->value,
                    'accrual_policy' => 'after_first_paid_period_end',
                ],
            ]);
        });
    }

    public function accrueEligibleRewards(?CarbonInterface $now = null): int
    {
        if (! $this->hasRequiredTables()) {
            return 0;
        }

        $accrued = 0;
        ContractorReferralReward::query()
            ->with(['commercialOrder', 'commercialPayment'])
            ->where('status', ContractorReferralReward::STATUS_PENDING)
            ->where('eligible_at', '<=', $now ?? now())
            ->orderBy('id')
            ->chunkById(100, function ($rewards) use (&$accrued): void {
                foreach ($rewards as $reward) {
                    $reason = $this->authoritativeCancellationReason($reward);
                    if ($reason !== null) {
                        $this->cancelReward($reward, $reason);

                        continue;
                    }

                    if ($this->accrueReward($reward)) {
                        $accrued++;
                    }
                }
            });

        return $accrued;
    }

    public function calculateRewardAmounts(int $firstPaymentAmount): array
    {
        return [
            'inviting_reward_amount' => min(
                $this->roundedPercent($firstPaymentAmount, (int) config('contractor_referrals.inviting_reward_percent', 30)),
                (int) config('contractor_referrals.inviting_reward_max_cents', 3_000_000),
            ),
            'invited_welcome_amount' => min(
                $this->roundedPercent($firstPaymentAmount, (int) config('contractor_referrals.invited_welcome_percent', 20)),
                (int) config('contractor_referrals.invited_welcome_max_cents', 2_000_000),
            ),
        ];
    }

    private function accrueReward(ContractorReferralReward $reward): bool
    {
        $inviting = Organization::query()->find($reward->inviting_organization_id);
        $invited = Organization::query()->find($reward->invited_organization_id);
        if ($inviting === null || $invited === null) {
            $this->cancelReward($reward, 'organization_not_found');

            return false;
        }

        $this->balanceService->getOrCreateOrganizationBalance($invited);
        $this->balanceService->getOrCreateOrganizationBalance($inviting);

        return DB::transaction(function () use ($reward, $inviting, $invited): bool {
            $locked = ContractorReferralReward::query()->whereKey($reward->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ContractorReferralReward::STATUS_PENDING) {
                return false;
            }

            $invitedTransaction = $this->balanceService->creditBalance($invited, $locked->invited_welcome_amount, 'Бонус за приглашение подрядчика', ['type' => 'contractor_referral_welcome', 'referral_reward_id' => $locked->id]);
            $invitingTransaction = $this->balanceService->creditBalance($inviting, $locked->inviting_reward_amount, 'Бонус за приглашённую организацию', ['type' => 'contractor_referral_reward', 'referral_reward_id' => $locked->id]);
            $locked->update([
                'status' => ContractorReferralReward::STATUS_ACCRUED,
                'invited_balance_transaction_id' => $invitedTransaction->id,
                'inviting_balance_transaction_id' => $invitingTransaction->id,
                'invited_welcome_accrued_at' => now(),
                'accrued_at' => now(),
            ]);

            return true;
        });
    }

    private function authoritativeCancellationReason(ContractorReferralReward $reward): ?string
    {
        $order = $reward->commercialOrder;
        $payment = $reward->commercialPayment;
        if ($order === null || $payment === null) {
            return 'commercial_source_not_found';
        }
        if ($order->status->value === 'refunded' || $payment->refunded_amount_minor > 0) {
            return 'commercial_order_refunded';
        }
        if ($order->status->value !== 'paid' || $payment->provider_status !== 'succeeded') {
            return 'commercial_order_cancelled';
        }

        return null;
    }

    private function isEligiblePayment(CommercialOrder $order, CommercialPayment $payment): bool
    {
        return (bool) config('contractor_referrals.enabled', true)
            && $order->kind === 'purchase'
            && $order->status->value === 'paid'
            && $payment->commercial_order_id === $order->id
            && $payment->provider_status === 'succeeded'
            && $payment->amount_minor > 0
            && $payment->refunded_amount_minor === 0;
    }

    private function cancelReward(ContractorReferralReward $reward, string $reason): void
    {
        $reward->update(['status' => ContractorReferralReward::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
    }

    private function detectAntiFraudReason(ContractorInvitation $invitation): ?string
    {
        if ($invitation->organization_id === $invitation->invited_organization_id) {
            return 'same_organization';
        }
        $inviting = $invitation->organization;
        $invited = $invitation->invitedOrganization;
        if ($inviting === null || $invited === null) {
            return 'organization_not_found';
        }
        foreach (['tax_number', 'email'] as $field) {
            $first = mb_strtolower(trim((string) $inviting->{$field}));
            $second = mb_strtolower(trim((string) $invited->{$field}));
            if ($first !== '' && $first === $second) {
                return 'same_'.$field;
            }
        }
        $invitingPhone = $this->normalizePhone((string) $inviting->phone);
        $invitedPhone = $this->normalizePhone((string) $invited->phone);
        if ($invitingPhone !== '' && $invitingPhone === $invitedPhone) {
            return 'same_phone';
        }

        return null;
    }

    private function roundedPercent(int $amount, int $percent): int
    {
        $roundTo = (int) config('contractor_referrals.round_to_cents', 100_000);
        $raw = (int) round($amount * $percent / 100);

        return $roundTo > 0 ? (int) (round($raw / $roundTo) * $roundTo) : $raw;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function hasRequiredTables(): bool
    {
        foreach (['contractor_invitations', 'contractor_referral_rewards', 'commercial_orders', 'commercial_payments', 'organization_balances', 'balance_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
