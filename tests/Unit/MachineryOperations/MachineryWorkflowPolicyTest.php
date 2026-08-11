<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetProjection;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryWorkflowPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class MachineryWorkflowPolicyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_canonical_lifecycle_technical_placement_and_operation_state_override_legacy_status(): void
    {
        $policy = $this->policyAllowingAll();
        $asset = $this->linkedAsset([
            'current_project_id' => 51,
            'technical_status' => AssetTechnicalStatus::Serviceable,
            'metadata' => ['machinery_operation_status' => 'in_operation'],
        ]);

        self::assertSame('in_operation', $policy->status($asset));

        $asset->organizationAsset->technical_status = AssetTechnicalStatus::Maintenance;
        self::assertSame('maintenance', $policy->status($asset));

        $asset->organizationAsset->lifecycle_status = AssetLifecycleStatus::Retired;
        self::assertSame('archived', $policy->status($asset));
    }

    public function test_available_actions_are_filtered_by_actor_permissions(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(
            static fn (User $user, string $permission): bool => in_array($permission, [
                'machinery-operations.requests.approve',
                'machinery-operations.delete',
            ], true),
        );
        $policy = new MachineryWorkflowPolicy($authorization);

        self::assertSame(['assign', 'archive'], $policy->availableActions($this->linkedAsset(), new User));
    }

    public function test_projection_has_identical_business_shape_for_canonical_and_legacy_records(): void
    {
        $policy = $this->policyAllowingAll();
        $projection = new MachineryAssetProjection($policy);
        $legacy = $this->legacyAsset();
        $linked = $this->linkedAsset();

        self::assertSame(array_keys($projection->project($legacy)), array_keys($projection->project($linked)));
        self::assertSame(900, $projection->project($linked)['organization_asset_id']);
        self::assertSame('Canonical excavator', $projection->project($linked)['name']);
        self::assertNull($projection->project($legacy)['organization_asset_id']);
        self::assertSame('Legacy excavator', $projection->project($legacy)['name']);
    }

    private function policyAllowingAll(): MachineryWorkflowPolicy
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);

        return new MachineryWorkflowPolicy($authorization);
    }

    /** @param array<string, mixed> $canonicalOverrides */
    private function linkedAsset(array $canonicalOverrides = []): MachineryAsset
    {
        $asset = $this->legacyAsset();
        $asset->organization_asset_id = 900;
        $canonical = new OrganizationAsset(array_merge([
            'organization_id' => 10,
            'name' => 'Canonical excavator',
            'inventory_number' => 'INV-900',
            'ownership_type' => 'owned',
            'lifecycle_status' => AssetLifecycleStatus::Active,
            'technical_status' => AssetTechnicalStatus::Serviceable,
            'metadata' => ['machinery_operation_status' => 'available'],
        ], $canonicalOverrides));
        $canonical->id = 900;
        $asset->setRelation('organizationAsset', $canonical);

        return $asset;
    }

    private function legacyAsset(): MachineryAsset
    {
        $asset = new MachineryAsset([
            'organization_id' => 10,
            'asset_code' => 'LEG-1',
            'name' => 'Legacy excavator',
            'inventory_number' => 'INV-LEG',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 1000,
        ]);
        $asset->id = 70;
        $asset->setRelation('organizationAsset', null);

        return $asset;
    }
}
