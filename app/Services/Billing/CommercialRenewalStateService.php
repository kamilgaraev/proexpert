<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\CommercialRenewalCycle;
use App\Models\OrganizationCommercialAccount;
use Illuminate\Support\Facades\DB;

final class CommercialRenewalStateService
{
    public function state(int $organizationId): array
    {
        $account = OrganizationCommercialAccount::query()->where('organization_id', $organizationId)->first();
        $cycle = $account === null ? null : CommercialRenewalCycle::query()->where('commercial_account_id', $account->id)->latest('id')->first();

        return [
            'status' => $account?->status?->value ?? 'free',
            'auto_renew_enabled' => (bool) $account?->auto_renew_enabled,
            'saved_method_available' => (bool) ($account?->saved_payment_method_active && $account?->saved_payment_method_id),
            'next_billing_at' => $account?->current_period_end_at?->toIso8601String(),
            'grace_started_at' => $account?->grace_started_at?->toIso8601String(),
            'grace_ends_at' => $account?->grace_ends_at?->toIso8601String(),
            'retry_status' => $cycle?->status,
            'attempt_count' => $cycle?->attempt_count ?? 0,
            'next_attempt_at' => $cycle?->next_attempt_at?->toIso8601String(),
        ];
    }

    public function disable(int $organizationId): array
    {
        DB::transaction(function () use ($organizationId): void {
            $account = OrganizationCommercialAccount::query()->where('organization_id', $organizationId)->lockForUpdate()->first();
            if ($account === null) {
                return;
            }
            $account->forceFill(['auto_renew_enabled' => false, 'saved_payment_method_active' => false])->save();
            CommercialRenewalCycle::query()->where('commercial_account_id', $account->id)->whereIn('status', ['due', 'grace'])->update(['status' => 'disabled', 'next_attempt_at' => null]);
        }, 3);

        return $this->state($organizationId);
    }
}
