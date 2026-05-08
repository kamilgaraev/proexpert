<?php

declare(strict_types=1);

namespace App\Services\Contractor;

use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\BalanceTransaction;
use App\Models\ContractorInvitation;
use App\Models\ContractorReferralReward;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContractorReferralRewardService
{
    public function __construct(
        private readonly BalanceServiceInterface $balanceService
    ) {
    }

    public function handleFirstPaidSubscription(
        OrganizationSubscription $subscription,
        int $firstPaymentAmount
    ): ?ContractorReferralReward {
        if (! $this->isEnabled() || $firstPaymentAmount <= 0 || ! $this->hasRequiredTables()) {
            return null;
        }

        if (ContractorReferralReward::query()
            ->where('invited_organization_id', $subscription->organization_id)
            ->exists()) {
            return null;
        }

        $invitation = ContractorInvitation::query()
            ->where('invited_organization_id', $subscription->organization_id)
            ->where('status', ContractorInvitation::STATUS_ACCEPTED)
            ->latest('accepted_at')
            ->first();

        if (! $invitation) {
            return null;
        }

        return DB::transaction(function () use ($subscription, $firstPaymentAmount, $invitation): ContractorReferralReward {
            $planSlug = (string) $subscription->plan?->slug;
            $amounts = $this->calculateRewardAmounts($firstPaymentAmount, $planSlug);
            $fraudReason = $this->detectAntiFraudReason($invitation);
            $status = $fraudReason === null
                ? ContractorReferralReward::STATUS_PENDING
                : ContractorReferralReward::STATUS_CANCELLED;

            $reward = ContractorReferralReward::query()->create([
                'contractor_invitation_id' => $invitation->id,
                'inviting_organization_id' => $invitation->organization_id,
                'invited_organization_id' => $invitation->invited_organization_id,
                'invited_subscription_id' => $subscription->id,
                'status' => $status,
                'first_payment_amount' => $firstPaymentAmount,
                'inviting_reward_amount' => $amounts['inviting_reward_amount'],
                'invited_welcome_amount' => $amounts['invited_welcome_amount'],
                'currency' => (string) config('contractor_referrals.currency', 'RUB'),
                'eligible_at' => $subscription->ends_at,
                'cancelled_at' => $fraudReason === null ? null : now(),
                'cancellation_reason' => $fraudReason,
                'meta' => [
                    'plan_slug' => $planSlug,
                    'rule' => $planSlug === 'start' ? 'start_fixed' : 'percent_with_caps',
                    'accrual_policy' => 'after_first_subscription_period_end',
                ],
            ]);

            return $reward->fresh() ?? $reward;
        });
    }

    public function accrueEligibleRewards(?Carbon $now = null): int
    {
        if (! $this->isEnabled() || ! $this->hasRequiredTables()) {
            return 0;
        }

        $now ??= now();
        $accruedCount = 0;

        ContractorReferralReward::query()
            ->with(['invitingOrganization', 'invitedSubscription'])
            ->where('status', ContractorReferralReward::STATUS_PENDING)
            ->where('eligible_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($rewards) use (&$accruedCount): void {
                foreach ($rewards as $reward) {
                    if ($this->subscriptionWasCancelledBeforeEligibility($reward)) {
                        $this->cancelReward($reward, 'subscription_cancelled_before_period_end');
                        continue;
                    }

                    $this->accrueReward($reward);
                    $accruedCount++;
                }
            });

        return $accruedCount;
    }

    public function calculateRewardAmounts(int $firstPaymentAmount, string $planSlug): array
    {
        if ($planSlug === 'start') {
            return [
                'inviting_reward_amount' => (int) config('contractor_referrals.start_plan.inviting_reward_cents', 200000),
                'invited_welcome_amount' => (int) config('contractor_referrals.start_plan.invited_welcome_cents', 100000),
            ];
        }

        return [
            'inviting_reward_amount' => min(
                $this->roundedPercent($firstPaymentAmount, (int) config('contractor_referrals.inviting_reward_percent', 30)),
                (int) config('contractor_referrals.inviting_reward_max_cents', 3000000)
            ),
            'invited_welcome_amount' => min(
                $this->roundedPercent($firstPaymentAmount, (int) config('contractor_referrals.invited_welcome_percent', 20)),
                (int) config('contractor_referrals.invited_welcome_max_cents', 2000000)
            ),
        ];
    }

    private function accrueReward(ContractorReferralReward $reward): void
    {
        $invitingOrganization = Organization::query()->find($reward->inviting_organization_id);
        $invitedOrganization = Organization::query()->find($reward->invited_organization_id);

        if (! $invitingOrganization) {
            $this->cancelReward($reward, 'inviting_organization_not_found');
            return;
        }

        if (! $invitedOrganization) {
            $this->cancelReward($reward, 'invited_organization_not_found');
            return;
        }

        DB::transaction(function () use ($reward, $invitingOrganization, $invitedOrganization): void {
            $lockedReward = ContractorReferralReward::query()
                ->whereKey($reward->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedReward || $lockedReward->status !== ContractorReferralReward::STATUS_PENDING) {
                return;
            }

            $invitedBalance = $this->balanceService->creditBalance(
                $invitedOrganization,
                $lockedReward->invited_welcome_amount,
                'Welcome-бонус за регистрацию по приглашению подрядчика',
                null,
                [
                    'type' => 'contractor_referral_welcome',
                    'referral_reward_id' => $lockedReward->id,
                    'contractor_invitation_id' => $lockedReward->contractor_invitation_id,
                    'inviting_organization_id' => $lockedReward->inviting_organization_id,
                    'eligible_at' => $lockedReward->eligible_at?->toIso8601String(),
                ]
            );

            $invitedTransaction = BalanceTransaction::query()
                ->where('organization_balance_id', $invitedBalance->id)
                ->latest('id')
                ->first();

            $invitingBalance = $this->balanceService->creditBalance(
                $invitingOrganization,
                $lockedReward->inviting_reward_amount,
                'Бонус за приглашенную организацию',
                null,
                [
                    'type' => 'contractor_referral_reward',
                    'referral_reward_id' => $lockedReward->id,
                    'contractor_invitation_id' => $lockedReward->contractor_invitation_id,
                    'invited_organization_id' => $lockedReward->invited_organization_id,
                    'eligible_at' => $lockedReward->eligible_at?->toIso8601String(),
                ]
            );

            $invitingTransaction = BalanceTransaction::query()
                ->where('organization_balance_id', $invitingBalance->id)
                ->latest('id')
                ->first();

            $lockedReward->update([
                'status' => ContractorReferralReward::STATUS_ACCRUED,
                'inviting_balance_transaction_id' => $invitingTransaction?->id,
                'invited_balance_transaction_id' => $invitedTransaction?->id,
                'invited_welcome_accrued_at' => now(),
                'accrued_at' => now(),
            ]);
        });
    }

    private function cancelReward(ContractorReferralReward $reward, string $reason): void
    {
        $reward->update([
            'status' => ContractorReferralReward::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    private function subscriptionWasCancelledBeforeEligibility(ContractorReferralReward $reward): bool
    {
        $subscription = $reward->invitedSubscription;

        if (! $subscription) {
            $this->cancelReward($reward, 'subscription_not_found');
            return true;
        }

        return $subscription->canceled_at !== null
            && $subscription->canceled_at->lessThanOrEqualTo($reward->eligible_at);
    }

    private function detectAntiFraudReason(ContractorInvitation $invitation): ?string
    {
        if ($invitation->organization_id === $invitation->invited_organization_id) {
            return 'same_organization';
        }

        $inviting = $invitation->organization;
        $invited = $invitation->invitedOrganization;

        if (! $inviting || ! $invited) {
            return 'organization_not_found';
        }

        if ($this->sameFilledValue($inviting->tax_number, $invited->tax_number)) {
            return 'same_tax_number';
        }

        if ($this->sameFilledValue($inviting->email, $invited->email)) {
            return 'same_email';
        }

        if ($this->sameFilledValue($this->normalizePhone($inviting->phone), $this->normalizePhone($invited->phone))) {
            return 'same_phone';
        }

        return null;
    }

    private function roundedPercent(int $amount, int $percent): int
    {
        $roundTo = (int) config('contractor_referrals.round_to_cents', 100000);
        $rawAmount = (int) round($amount * $percent / 100);

        if ($roundTo <= 0) {
            return $rawAmount;
        }

        return (int) (round($rawAmount / $roundTo) * $roundTo);
    }

    private function sameFilledValue(?string $first, ?string $second): bool
    {
        $first = mb_strtolower(trim((string) $first));
        $second = mb_strtolower(trim((string) $second));

        return $first !== '' && $first === $second;
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    private function isEnabled(): bool
    {
        return (bool) config('contractor_referrals.enabled', true);
    }

    private function hasRequiredTables(): bool
    {
        return Schema::hasTable('contractor_invitations')
            && Schema::hasTable('contractor_referral_rewards')
            && Schema::hasTable('organization_balances')
            && Schema::hasTable('balance_transactions');
    }
}
