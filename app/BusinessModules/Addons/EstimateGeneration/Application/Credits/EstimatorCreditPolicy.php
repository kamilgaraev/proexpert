<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\LegacyPaidEstimateConversionEvidence;
use App\Models\OrganizationResourceAllocation;

/** Unknown legacy balances keep their existing quota and billing path until a proven conversion is recorded. */
final class EstimatorCreditPolicy
{
    public static function enabledForOrganization(int $organizationId): bool
    {
        if ($organizationId < 1 || ! config('estimate-generation.credits.enabled', false)) {
            return false;
        }
        $allocations = OrganizationResourceAllocation::query()->where('organization_id', $organizationId)
            ->where('limit_key', 'ai_estimates_month')->where('quantity', '>', 0)->active()->orderBy('id')->get();

        return LegacyPaidEstimateConversionEvidence::unconverted($allocations)->isEmpty();
    }

    public static function allocationHash(OrganizationResourceAllocation $allocation): string
    {
        return LegacyPaidEstimateConversionEvidence::allocationHash($allocation);
    }
}
