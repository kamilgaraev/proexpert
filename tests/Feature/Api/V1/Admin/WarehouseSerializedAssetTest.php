<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\AssetManagement\Models\AssetCustodyEvent;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetReadRepository;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryWorkflowPolicy;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseSerializedAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    public function test_quantity_catalog_item_does_not_create_canonical_instances(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit((int) $context->organization->id);
        $warehouse = $this->createWarehouse((int) $context->organization->id, 'MAIN');
        $this->allowAdminAccess();
        $this->actingAs($context->user, 'api_admin');

        $response = $this->postJson('/api/v1/admin/assets', [
            'name' => 'Саморезы 4x50',
            'code' => 'SCR-4X50',
            'measurement_unit_id' => $unit->id,
            'asset_type' => Asset::TYPE_TOOL,
            'accounting_mode' => 'quantitative',
            'warehouse_id' => $warehouse->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.accounting_mode', 'quantitative');
        self::assertSame(0, OrganizationAsset::query()->count());

        $this
            ->postJson('/api/v1/admin/assets/'.$response->json('data.id').'/instances', [
                'warehouse_id' => $warehouse->id,
                'instances' => [['inventory_number' => 'SCR-001']],
            ])
            ->assertStatus(422);
        self::assertSame(0, OrganizationAsset::query()->count());
    }

    public function test_serialized_receipt_creates_exact_instances_with_stable_unique_qr_codes(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit((int) $context->organization->id);
        $warehouse = $this->createWarehouse((int) $context->organization->id, 'MAIN');
        $this->allowAdminAccess();
        $this->actingAs($context->user, 'api_admin');

        $catalog = $this->postJson('/api/v1/admin/assets', [
            'name' => 'Виброплита Wacker',
            'code' => 'VP-WACKER',
            'measurement_unit_id' => $unit->id,
            'asset_type' => Asset::TYPE_EQUIPMENT,
            'accounting_mode' => 'serialized',
            'warehouse_id' => $warehouse->id,
        ])->assertCreated();

        $receipt = $this
            ->postJson('/api/v1/admin/assets/'.$catalog->json('data.id').'/instances', [
                'warehouse_id' => $warehouse->id,
                'instances' => [
                    ['inventory_number' => 'VP-001', 'serial_number' => 'WACKER-A'],
                    ['inventory_number' => 'VP-002', 'serial_number' => 'WACKER-B', 'qr_code' => 'QR-VP-002'],
                ],
            ]);

        $receipt->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.inventory_number', 'VP-001')
            ->assertJsonPath('data.1.qr_code', 'QR-VP-002');

        $instances = OrganizationAsset::query()->orderBy('inventory_number')->get();
        self::assertCount(2, $instances);
        self::assertNotNull($instances[0]->qr_code);
        self::assertNotSame($instances[0]->qr_code, $instances[1]->qr_code);
        self::assertSame($warehouse->id, $instances[0]->current_warehouse_id);
        self::assertSame(2, AssetCustodyEvent::query()->where('event_type', 'created')->count());
        self::assertSame(2, MachineryAsset::query()->count());
        self::assertSame(
            $instances->pluck('id')->all(),
            MachineryAsset::query()->orderBy('inventory_number')->pluck('organization_asset_id')->all(),
        );

        $this
            ->getJson('/api/v1/admin/assets?warehouse_id='.$warehouse->id)
            ->assertOk()
            ->assertJsonPath('data.0.serialized_summary.total_count', 2)
            ->assertJsonPath('data.0.serialized_summary.active_count', 2)
            ->assertJsonPath('data.0.serialized_summary.in_warehouse_count', 2)
            ->assertJsonPath('data.0.serialized_summary.with_responsible_count', 0)
            ->assertJsonPath('data.0.serialized_summary.retired_count', 0);

        $registry = app(MachineryAssetReadRepository::class)->paginate((int) $context->organization->id, 20);
        self::assertSame(2, $registry->total());
        $registryAsset = $registry->items()[0];
        self::assertSame('custody', $registryAsset->organizationAsset->operationProfile->operational_mode->value);
        self::assertSame([], app(MachineryWorkflowPolicy::class)->availableActions($registryAsset, $context->user));

        $generatedQr = $instances[0]->qr_code;
        $this
            ->getJson('/api/v1/admin/organization-assets?warehouse_id='.$warehouse->id)
            ->assertOk()
            ->assertJsonPath('data.0.qr_code', $generatedQr);
        self::assertSame($generatedQr, $instances[0]->fresh()->qr_code);
    }

    public function test_serialized_receipt_requires_every_inventory_number_and_rolls_back_duplicates(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit((int) $context->organization->id);
        $warehouse = $this->createWarehouse((int) $context->organization->id, 'MAIN');
        $material = $this->createSerializedMaterial((int) $context->organization->id, (int) $unit->id);
        $this->allowAdminAccess();
        $this->actingAs($context->user, 'api_admin');

        $this
            ->postJson("/api/v1/admin/assets/{$material->id}/instances", [
                'warehouse_id' => $warehouse->id,
                'instances' => [
                    ['inventory_number' => 'DUP-001'],
                    ['inventory_number' => 'DUP-001'],
                ],
            ])
            ->assertStatus(422);
        self::assertSame(0, OrganizationAsset::query()->count());

        $this
            ->postJson("/api/v1/admin/assets/{$material->id}/instances", [
                'warehouse_id' => $warehouse->id,
                'instances' => [
                    ['inventory_number' => 'VALID-001'],
                    ['serial_number' => 'NO-INVENTORY'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('instances.1.inventory_number');
        self::assertSame(0, OrganizationAsset::query()->count());
    }

    public function test_issue_and_return_update_custody_and_keep_append_only_history(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit((int) $context->organization->id);
        $warehouse = $this->createWarehouse((int) $context->organization->id, 'MAIN');
        $material = $this->createSerializedMaterial((int) $context->organization->id, (int) $unit->id);
        $responsible = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($responsible->id, ['is_owner' => false, 'is_active' => true]);
        $this->allowAdminAccess();
        $this->actingAs($context->user, 'api_admin');

        $assetId = $this
            ->postJson("/api/v1/admin/assets/{$material->id}/instances", [
                'warehouse_id' => $warehouse->id,
                'instances' => [['inventory_number' => 'DRILL-001']],
            ])->assertCreated()->json('data.0.id');

        $issue = $this
            ->postJson("/api/v1/admin/organization-assets/{$assetId}/issue", [
                'responsible_user_id' => $responsible->id,
                'expected_return_at' => now()->addWeek()->toIso8601String(),
                'reason' => 'Выдано монтажнику',
            ]);

        $issue->assertOk()
            ->assertJsonPath('data.responsible_user_id', $responsible->id)
            ->assertJsonPath('data.current_warehouse_id', null);

        $issued = OrganizationAsset::query()->findOrFail($assetId);
        $issueEvent = $issued->custodyEvents()->where('event_type', 'issued')->sole();
        self::assertSame('Выдано монтажнику', $issueEvent->metadata['reason']);
        self::assertNotNull($issueEvent->metadata['expected_return_at']);

        $this
            ->getJson("/api/v1/admin/organization-assets/{$assetId}/events")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.event_type', 'issued')
            ->assertJsonPath('data.0.to_user.id', $responsible->id)
            ->assertJsonPath('data.0.metadata.reason', 'Выдано монтажнику');

        $this
            ->getJson('/api/v1/admin/organization-assets?responsible_user_id='.$responsible->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $assetId);

        $this
            ->postJson("/api/v1/admin/organization-assets/{$assetId}/return", [
                'warehouse_id' => $warehouse->id,
                'reason' => 'Возвращено после смены',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_warehouse_id', $warehouse->id)
            ->assertJsonPath('data.responsible_user_id', null);

        self::assertSame(['created', 'issued', 'returned'], $issued->custodyEvents()->orderBy('id')->pluck('event_type')->all());

        $this
            ->postJson("/api/v1/admin/organization-assets/{$assetId}/retire", [
                'outcome' => 'retired',
                'reason' => 'Износ после эксплуатации',
            ])
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', 'retired')
            ->assertJsonPath('data.current_warehouse_id', null)
            ->assertJsonPath('data.responsible_user_id', null);

        self::assertSame(
            ['created', 'issued', 'returned', 'retired'],
            $issued->custodyEvents()->orderBy('id')->pluck('event_type')->all(),
        );
        self::assertSame(
            'Износ после эксплуатации',
            $issued->custodyEvents()->latest('id')->firstOrFail()->metadata['reason'],
        );
        self::assertNull($issued->custodyEvents()->latest('id')->firstOrFail()->to_warehouse_id);
        self::assertNull($issued->custodyEvents()->latest('id')->firstOrFail()->to_project_id);
        self::assertNull($issued->custodyEvents()->latest('id')->firstOrFail()->to_user_id);

        $this
            ->postJson("/api/v1/admin/organization-assets/{$assetId}/retire", [
                'outcome' => 'lost',
                'reason' => 'Повторная операция',
            ])
            ->assertStatus(422);

        $this
            ->getJson('/api/v1/admin/assets?warehouse_id='.$warehouse->id)
            ->assertOk()
            ->assertJsonPath('data.0.serialized_summary.total_count', 1)
            ->assertJsonPath('data.0.serialized_summary.active_count', 0)
            ->assertJsonPath('data.0.serialized_summary.in_warehouse_count', 0)
            ->assertJsonPath('data.0.serialized_summary.retired_count', 1);
    }

    public function test_serialized_history_and_retirement_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit((int) $foreignContext->organization->id);
        $foreignMaterial = $this->createSerializedMaterial(
            (int) $foreignContext->organization->id,
            (int) $foreignUnit->id,
        );
        $foreignAsset = OrganizationAsset::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'material_id' => $foreignMaterial->id,
            'name' => 'Чужой экземпляр',
            'inventory_number' => 'FOREIGN-ASSET-001',
            'accounting_mode' => 'serialized',
            'ownership_type' => 'owned',
            'lifecycle_status' => 'active',
            'technical_status' => 'serviceable',
        ]);

        $this->allowAdminAccess();
        $this->actingAs($context->user, 'api_admin');

        $this
            ->getJson("/api/v1/admin/organization-assets/{$foreignAsset->id}/events")
            ->assertNotFound();
        $this
            ->postJson("/api/v1/admin/organization-assets/{$foreignAsset->id}/retire", [
                'outcome' => 'retired',
                'reason' => 'Недопустимая межорганизационная операция',
            ])
            ->assertStatus(422);

        self::assertSame('active', $foreignAsset->fresh()->lifecycle_status->value);
    }

    private function createUnit(int $organizationId): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Штука',
            'short_name' => 't4-'.$organizationId,
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
    }

    private function createWarehouse(int $organizationId, string $code): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Основной склад',
            'code' => $code,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
    }

    private function createSerializedMaterial(int $organizationId, int $unitId): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Шуруповерт',
            'code' => 'DRILL',
            'measurement_unit_id' => $unitId,
            'additional_properties' => [
                'asset_type' => Asset::TYPE_TOOL,
                'accounting_mode' => 'serialized',
            ],
            'is_active' => true,
        ]);
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                },
            );
        });
    }
}
