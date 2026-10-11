<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorProductAccess;
use App\Services\Entitlements\OrganizationEntitlementService;
use Mockery;
use PHPUnit\Framework\TestCase;

final class EstimatorProductAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_product_access_is_read_again_and_revocation_cannot_reuse_an_old_allowance(): void
    {
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->expects('hasModuleAccess')->twice()->with(7, 'ai-estimates')->andReturn(true, false);
        $gate = new EstimatorProductAccess($entitlements);
        $gate->requireAvailable(7);
        $this->expectExceptionMessage('estimator_product_not_available');
        $gate->requireAvailable(7);
    }

    public function test_an_assistant_product_does_not_supply_estimator_entitlement(): void
    {
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->expects('hasModuleAccess')->once()->with(7, 'ai-estimates')->andReturn(false);
        self::assertFalse((new EstimatorProductAccess($entitlements))->available(7));
    }
}
