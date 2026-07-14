<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class CommercialBillingNotificationService
{
    public function process(CarbonInterface $at): void
    {
        $this->processRenewalLifecycle($at);
        $this->processTrialLifecycle($at);
    }

    public function processRenewalLifecycle(CarbonInterface $at): void
    {
        $now = CarbonImmutable::instance($at);
        foreach ([3, 1] as $days) {
            $targetStart = $now->setTimezone('Europe/Moscow')->addDays($days)->startOfDay()->utc();
            $targetEnd = $targetStart->addDay();
            OrganizationCommercialAccount::query()->where('auto_renew_enabled', true)
                ->where('current_period_end_at', '>=', $targetStart)
                ->where('current_period_end_at', '<', $targetEnd)
                ->each(
                    fn (OrganizationCommercialAccount $account) => $this->notify($account, 'commercial_upcoming_'.$days.'_'.$account->current_period_end_at?->toDateString(), 'billing.renewal.upcoming_'.$days),
                );
        }
        OrganizationCommercialAccount::query()->where('status', 'grace')->each(fn (OrganizationCommercialAccount $account) => $this->notify($account, 'commercial_grace_'.$now->toDateString(), 'billing.renewal.grace_update'));
        OrganizationCommercialAccount::query()->where('status', 'suspended')->whereNotNull('grace_ends_at')->each(fn (OrganizationCommercialAccount $account) => $this->notify($account, 'commercial_grace_ended_'.$account->id, 'billing.renewal.grace_ended'));
    }

    public function processTrialLifecycle(CarbonInterface $at): void
    {
        $now = CarbonImmutable::instance($at);
        OrganizationPackageSubscription::query()->where('status', 'trialing')->whereBetween('trial_ends_at', [$now->addHours(23), $now->addHours(24)])->each(function (OrganizationPackageSubscription $row): void {
            $this->notify($row->commercialAccount, 'commercial_trial_ending_'.$row->id, 'billing.renewal.trial_ending', ['package_slug' => $row->package_slug]);
        });
        OrganizationPackageSubscription::query()->where('status', 'trialing')->where('trial_ends_at', '<=', $now)->each(function (OrganizationPackageSubscription $row) use ($now): void {
            $row->forceFill(['status' => 'expired', 'canceled_at' => $now])->save();
            $this->notify($row->commercialAccount, 'commercial_trial_ended_'.$row->id, 'billing.renewal.trial_ended', ['package_slug' => $row->package_slug]);
        });
    }

    public function notify(OrganizationCommercialAccount $account, string $type, string $messageKey, array $data = []): void
    {
        if ($account->responsible_user_id === null) {
            return;
        }
        DB::transaction(function () use ($account, $type, $messageKey, $data): void {
            $inserted = DB::table('commercial_billing_notification_keys')->insertOrIgnore([
                'idempotency_key' => hash('sha256', implode('|', [$account->id, $account->responsible_user_id, $type])),
                'organization_id' => $account->organization_id,
                'commercial_account_id' => $account->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted !== 1) {
                return;
            }
            Notification::query()->create([
                'type' => $type, 'notifiable_type' => User::class, 'notifiable_id' => $account->responsible_user_id,
                'organization_id' => $account->organization_id, 'notification_type' => 'billing', 'priority' => 'normal',
                'channels' => ['in_app'], 'delivery_status' => [],
                'data' => ['title' => trans_message('billing.webhook.title'), 'message' => trans_message($messageKey), 'account_id' => $account->id] + $data,
            ]);
        }, 3);
    }
}
