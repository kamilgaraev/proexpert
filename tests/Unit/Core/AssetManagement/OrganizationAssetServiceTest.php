<?php

declare(strict_types=1);

namespace Tests\Unit\Core\AssetManagement;

use App\BusinessModules\Core\AssetManagement\DTO\AssetPlacementData;
use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Enums\AssetAccountingMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\AssetCustodyEvent;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Core\AssetManagement\Services\OrganizationAssetService;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use DomainException;
use Illuminate\Database\QueryException;
use LogicException;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class OrganizationAssetServiceTest extends TestCase
{
    public function test_create_persists_canonical_asset_default_profile_and_initial_custody_event(): void
    {
        $context = AdminApiTestContext::create();
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Виброплита Wacker',
            'code' => 'VP-WACKER',
        ]);
        $warehouse = $this->createWarehouse((int) $context->organization->id, 'MAIN');

        $asset = $this->service()->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData(
                name: 'Виброплита Wacker №1',
                inventoryNumber: 'INV-0001',
                materialId: (int) $material->id,
                qrCode: 'QR-0001',
                placement: new AssetPlacementData(warehouseId: (int) $warehouse->id),
                actorId: (int) $context->user->id,
            ),
        );

        self::assertSame(AssetAccountingMode::Serialized, $asset->accounting_mode);
        self::assertSame(AssetLifecycleStatus::Active, $asset->lifecycle_status);
        self::assertSame(AssetTechnicalStatus::Serviceable, $asset->technical_status);
        self::assertSame($warehouse->id, $asset->current_warehouse_id);
        self::assertNull($asset->current_project_id);
        self::assertNull($asset->responsible_user_id);
        self::assertSame(AssetOperationalMode::Custody, $asset->operationProfile->operational_mode);
        self::assertFalse($asset->operationProfile->tracks_meter);
        self::assertFalse($asset->operationProfile->tracks_fuel);
        self::assertFalse($asset->operationProfile->tracks_production);
        self::assertFalse($asset->operationProfile->maintenance_enabled);

        $event = $asset->custodyEvents()->sole();
        self::assertSame('created', $event->event_type);
        self::assertSame($warehouse->id, $event->to_warehouse_id);
        self::assertSame($context->user->id, $event->actor_user_id);
    }

    public function test_inventory_number_is_unique_only_inside_organization(): void
    {
        $first = AdminApiTestContext::create();
        $second = AdminApiTestContext::create();

        $this->service()->create((int) $first->organization->id, $this->assetData('INV-SHARED', 'QR-SHARED'));
        $this->service()->create((int) $second->organization->id, $this->assetData('INV-SHARED', 'QR-SHARED'));

        $this->expectException(QueryException::class);
        $this->service()->create((int) $first->organization->id, $this->assetData('INV-SHARED', 'QR-OTHER'));
    }

    public function test_qr_code_is_unique_inside_organization(): void
    {
        $context = AdminApiTestContext::create();
        $this->service()->create((int) $context->organization->id, $this->assetData('INV-FIRST', 'QR-SHARED'));

        $this->expectException(QueryException::class);
        $this->service()->create((int) $context->organization->id, $this->assetData('INV-SECOND', 'QR-SHARED'));
    }

    public function test_serial_number_is_unique_inside_organization(): void
    {
        $context = AdminApiTestContext::create();
        $this->service()->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData('Первый', 'INV-SERIAL-1', serialNumber: 'SERIAL-SHARED'),
        );

        $this->expectException(QueryException::class);
        $this->service()->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData('Второй', 'INV-SERIAL-2', serialNumber: 'SERIAL-SHARED'),
        );
    }

    public function test_organization_scope_never_returns_another_organizations_assets(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $own = $this->service()->create((int) $first->id, $this->assetData('INV-OWN', null));
        $this->service()->create((int) $second->id, $this->assetData('INV-FOREIGN', null));

        self::assertSame([$own->id], OrganizationAsset::query()->forOrganization((int) $first->id)->pluck('id')->all());
    }

    public function test_create_rejects_cross_organization_references_without_partial_writes(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = Organization::factory()->create();
        $foreignMaterial = Material::query()->create([
            'organization_id' => $foreign->id,
            'name' => 'Чужая номенклатура',
        ]);

        try {
            $this->service()->create(
                (int) $context->organization->id,
                new CreateOrganizationAssetData(
                    name: 'Недопустимый актив',
                    inventoryNumber: 'INV-FOREIGN',
                    materialId: (int) $foreignMaterial->id,
                    actorId: (int) $context->user->id,
                ),
            );
            self::fail('Foreign material must be rejected.');
        } catch (DomainException) {
            self::assertSame(0, OrganizationAsset::query()->count());
        }

        $foreignWarehouse = $this->createWarehouse((int) $foreign->id, 'FOREIGN');

        $this->expectException(DomainException::class);
        $this->service()->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData(
                name: 'Недопустимое размещение',
                inventoryNumber: 'INV-FOREIGN-PLACE',
                placement: new AssetPlacementData(warehouseId: (int) $foreignWarehouse->id),
                actorId: (int) $context->user->id,
            ),
        );
    }

    public function test_move_requires_exactly_one_destination_and_records_ordered_append_only_history(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse((int) $context->organization->id, 'MOVE');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = $this->service()->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData(
                name: 'Шуруповерт',
                inventoryNumber: 'INV-MOVE',
                placement: new AssetPlacementData(warehouseId: (int) $warehouse->id),
                actorId: (int) $context->user->id,
            ),
        );

        foreach ([
            new AssetPlacementData,
            new AssetPlacementData(warehouseId: (int) $warehouse->id, projectId: (int) $project->id),
        ] as $invalidPlacement) {
            try {
                $this->service()->move($asset, $invalidPlacement, (int) $context->user->id);
                self::fail('Ambiguous placement must be rejected.');
            } catch (DomainException) {
                self::assertSame($warehouse->id, $asset->fresh()->current_warehouse_id);
            }
        }

        $moved = $this->service()->move(
            $asset,
            new AssetPlacementData(projectId: (int) $project->id),
            (int) $context->user->id,
        );

        self::assertNull($moved->current_warehouse_id);
        self::assertSame($project->id, $moved->current_project_id);
        self::assertSame(['created', 'moved'], $moved->custodyEvents()->orderBy('id')->pluck('event_type')->all());
        $move = $moved->custodyEvents()->latest('id')->firstOrFail();
        self::assertSame($warehouse->id, $move->from_warehouse_id);
        self::assertSame($project->id, $move->to_project_id);

        $this->expectException(LogicException::class);
        $move->update(['event_type' => 'tampered']);
    }

    public function test_move_rejects_foreign_project_and_actor(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $asset = $this->service()->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData(name: 'Бетономешалка', inventoryNumber: 'INV-MIXER'),
        );
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);

        try {
            $this->service()->move(
                $asset,
                new AssetPlacementData(projectId: (int) $foreignProject->id),
                (int) $context->user->id,
            );
            self::fail('Foreign project must be rejected.');
        } catch (DomainException) {
            self::assertSame(0, AssetCustodyEvent::query()->count());
        }

        $this->expectException(DomainException::class);
        $this->service()->move(
            $asset,
            new AssetPlacementData(userId: (int) $context->user->id),
            (int) $foreign->user->id,
        );
    }

    public function test_available_actions_are_state_and_permission_aware(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $this->mock(\App\Domain\Authorization\Services\AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturnUsing(
                static fn ($actor, string $permission): bool => in_array($permission, [
                    'warehouse.transfers',
                    'machinery-operations.shifts.create',
                    'machinery-operations.downtime.manage',
                ], true),
            );
        });
        $asset = $this->service()->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData(
                name: 'Экскаватор',
                inventoryNumber: 'INV-ACTIONS',
                operationalMode: AssetOperationalMode::ShiftOperation,
                tracksMeter: true,
                maintenanceEnabled: true,
            ),
        );

        self::assertContains('move', $this->service()->availableActions($asset, $context->user));
        self::assertContains('start_shift', $this->service()->availableActions($asset, $context->user));
        self::assertContains('send_to_maintenance', $this->service()->availableActions($asset, $context->user));
        self::assertNotContains('retire', $this->service()->availableActions($asset, $context->user));
        self::assertSame([], $this->service()->availableActions($asset, $foreign->user));

        $asset->update(['lifecycle_status' => AssetLifecycleStatus::Retired]);

        self::assertSame([], $this->service()->availableActions($asset->fresh(), $context->user));
    }

    private function service(): OrganizationAssetService
    {
        return $this->app->make(OrganizationAssetService::class);
    }

    private function assetData(string $inventoryNumber, ?string $qrCode): CreateOrganizationAssetData
    {
        return new CreateOrganizationAssetData(
            name: "Актив {$inventoryNumber}",
            inventoryNumber: $inventoryNumber,
            qrCode: $qrCode,
        );
    }

    private function createWarehouse(int $organizationId, string $code): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => "Склад {$code}",
            'code' => $code,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);
    }
}
