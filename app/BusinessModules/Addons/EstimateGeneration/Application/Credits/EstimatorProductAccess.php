<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\Services\Entitlements\OrganizationEntitlementService;
use DomainException;

/** Product entitlement is independent from actor permissions and the shared wallet. */
final readonly class EstimatorProductAccess
{
    public function __construct(private OrganizationEntitlementService $entitlements) {}

    public function available(int $organizationId): bool
    {
        return $organizationId > 0 && $this->entitlements->hasModuleAccess($organizationId, 'ai-estimates');
    }

    public function requireAvailable(int $organizationId): void
    {
        if (! $this->available($organizationId)) {
            throw new DomainException('estimator_product_not_available');
        }
    }
}
