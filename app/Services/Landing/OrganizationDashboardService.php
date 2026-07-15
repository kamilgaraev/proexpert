<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;

final class OrganizationDashboardService
{
    public function getDashboardData(Organization $organization): array
    {
        $account = OrganizationCommercialAccount::query()
            ->where('organization_id', $organization->id)
            ->first();
        $packages = OrganizationPackageSubscription::query()
            ->where('organization_id', $organization->id)
            ->active()
            ->orderBy('package_slug')
            ->get();

        return [
            'commercial' => $account === null ? null : [
                'status' => $account->status->value,
                'offer_type' => $account->offer_type?->value,
                'current_period_end_at' => $account->current_period_end_at?->toIso8601String(),
                'auto_renew_enabled' => $account->auto_renew_enabled,
            ],
            'packages' => $packages->map(static fn (OrganizationPackageSubscription $package): array => [
                'slug' => $package->package_slug,
                'status' => $package->status->value,
                'access_source' => $package->access_source->value,
                'current_period_end_at' => $package->current_period_end_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
