<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CommercialAccountStatus;
use App\Models\CommercialContourChange;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use Carbon\CarbonInterface;

final class CommercialContourChangeCancellationService
{
    public function cancelForCorporateAccount(
        OrganizationCommercialAccount $account,
        CarbonInterface $canceledAt,
    ): int {
        if ($account->status !== CommercialAccountStatus::Corporate) {
            return 0;
        }

        $changes = CommercialContourChange::query()
            ->where('organization_id', $account->organization_id)
            ->where('commercial_account_id', $account->id)
            ->where('status', 'scheduled')
            ->lockForUpdate()
            ->get();

        foreach ($changes as $change) {
            $removedSlugs = array_values(array_diff(
                $change->current_package_slugs,
                $change->target_package_slugs,
            ));

            if ($removedSlugs !== []) {
                OrganizationPackageSubscription::query()
                    ->where('organization_id', $account->organization_id)
                    ->where('commercial_account_id', $account->id)
                    ->whereIn('access_source', ['paid_package', 'full_suite'])
                    ->where('status', 'scheduled_for_removal')
                    ->whereIn('package_slug', $removedSlugs)
                    ->update([
                        'status' => 'active',
                        'cancel_at' => null,
                        'canceled_at' => null,
                    ]);
            }

            $change->forceFill([
                'status' => 'canceled',
                'canceled_at' => $canceledAt,
            ])->save();
        }

        return $changes->count();
    }
}
