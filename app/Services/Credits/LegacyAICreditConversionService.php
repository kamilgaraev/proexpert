<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\CommercialOrder;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationResourceAllocation;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Support\Str;

final class LegacyAICreditConversionService
{
    public function __construct(private readonly AICreditService $credits, private readonly UsageTracker $usage, private readonly OrganizationEntitlementService $entitlements) {}

    public function report(Organization $organization, bool $execute = false): array
    {
        if (! $execute) { return $this->collectReport($organization, false); }
        return \Illuminate\Support\Facades\DB::transaction(function () use ($organization): array {
            OrganizationCommercialAccount::query()->where('organization_id', $organization->getKey())->lockForUpdate()->first();
            return $this->collectReport($organization, true);
        }, 3);
    }

    private function collectReport(Organization $organization, bool $execute): array
    {
        $organizationId = (int) $organization->getKey();
        $account = OrganizationCommercialAccount::query()->where('organization_id', $organizationId)->first();
        $active = $account !== null && $account->status->value === 'active'
            && $account->current_period_start_at !== null && $account->current_period_start_at <= now()
            && $account->current_period_end_at !== null && $account->current_period_end_at > now()
            && $this->entitlements->hasModuleAccess($organizationId, 'ai-assistant');
        $paidPeriod = $active ? CommercialOrder::query()->where('organization_id', $organizationId)->where('commercial_account_id', $account->getKey())->where('status', 'paid')->where('kind', '!=', 'ai_credits')->where('period_start_at', $account->current_period_start_at)->where('period_end_at', $account->current_period_end_at)->exists() : false;
        $module = Module::query()->where('slug', 'ai-assistant')->first();
        $limit = (int) ($module?->limits['max_ai_requests_per_month']
            ?? $module?->pricing_config['legacy_ai_request_allowance']
            ?? 5000);
        $used = $this->usage->getMonthlyUsage($organizationId, true);
        $included = $paidPeriod ? max(0, $limit - $used) : 0;
        if ($paidPeriod && \App\Models\Credits\AICreditLedgerEntry::query()->where('organization_id', $organizationId)->whereIn('idempotency_key', ['paid-period:'.$account->getKey().':'.$account->current_period_start_at->toAtomString(), 'legacy-base:'.$account->getKey().':'.$account->current_period_start_at->toAtomString()])->exists()) { $included = 0; }
        $addonConsumed = max(0, $used - $limit);
        $addons = [];
        $paidAddons = OrganizationResourceAllocation::query()->where('organization_id', $organizationId)->where('source', 'paid_addon')->where('limit_key', 'ai_requests_month')->active()->orderBy('id')->get();
        foreach ($paidAddons as $addon) {
            $paidOrderId = $addon->metadata['commercial_order_id'] ?? null;
            if (!is_string($paidOrderId) || !Str::isUuid($paidOrderId)) { continue; }
            $paid = CommercialOrder::query()->where('organization_id', $organizationId)->where('public_id', $paidOrderId)->where('status', 'paid')->exists();
            if (! $paid || ! $active) { continue; }
            $quantity = (int) $addon->quantity;
            $consumed = min($addonConsumed, $quantity);
            $addonConsumed -= $consumed;
            $remaining = max(0, $quantity - $consumed);
            if ($remaining === 0) { continue; }
            if (\App\Models\Credits\AICreditLedgerEntry::query()->where('organization_id', $organizationId)->where('idempotency_key', 'legacy-paid-addon:'.$addon->getKey())->exists()) { continue; }
            $addons[] = ['allocation_id' => (int) $addon->getKey(), 'units' => $remaining];
            if ($execute) { $this->credits->grant($organization, $remaining * 100, 'conversion', null, 'legacy-paid-addon:'.$addon->getKey(), metadata: ['allocation_id' => $addon->getKey(), 'ratio' => '1:1']); }
        }
        if ($execute && $included > 0) {
            $this->credits->grant($organization, $included * 100, 'subscription', $account->current_period_end_at, 'legacy-base:'.$account->getKey().':'.$account->current_period_start_at->toAtomString(), metadata: ['ratio' => '1:1', 'used_requests' => $used]);
        }
        return ['organization_id' => $organizationId, 'eligible_paid_period' => $paidPeriod, 'included_units' => $included, 'included_expires_at' => $paidPeriod ? $account->current_period_end_at->toAtomString() : null, 'paid_addons' => $addons, 'total_units' => $included + array_sum(array_column($addons, 'units')), 'dry_run' => ! $execute];
    }
}
