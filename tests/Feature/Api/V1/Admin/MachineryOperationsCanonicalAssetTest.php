<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetReadRepository;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\WebInterfaceSecurityMiddleware;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryOperationsCanonicalAssetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(WebInterfaceSecurityMiddleware::class);
    }

    public function test_admin_workflow_writes_authoritative_canonical_state_and_shadow_links(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();

        $created = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/assets', [
            'asset_code' => 'CAN-EXC-1',
            'name' => 'Canonical excavator',
            'inventory_number' => 'CAN-INV-1',
            'ownership_type' => 'owned',
            'fuel_type' => 'diesel',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Canonical excavator')
            ->assertJsonPath('data.status', 'available');
        $legacyId = (int) $created->json('data.id');
        $canonicalId = (int) $created->json('data.organization_asset_id');
        self::assertGreaterThan(0, $canonicalId);

        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/assets', [
            'asset_code' => 'CAN-OTHER-2',
            'name' => 'Other asset',
            'inventory_number' => 'OTHER-INV-2',
        ])->assertCreated();
        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery-operations/assets?search=can-inv-1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $legacyId);

        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/machinery-operations/assets/{$legacyId}/assign", [
            'project_id' => $project->id,
            'planned_start_at' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertDatabaseHas('machinery_assignments', [
            'asset_id' => $legacyId,
            'organization_asset_id' => $canonicalId,
        ]);
        $canonical = OrganizationAsset::query()->findOrFail($canonicalId);
        self::assertSame((int) $project->id, (int) $canonical->current_project_id);
        self::assertSame('assigned', $canonical->metadata['machinery_operation_status']);

        $maintenance = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/machinery-operations/maintenance-orders', [
            'asset_id' => $legacyId,
            'title' => 'Control service',
        ])->assertCreated();
        self::assertSame(AssetTechnicalStatus::Maintenance, $canonical->refresh()->technical_status);

        $this->withHeaders($context->authHeaders())->postJson(
            '/api/v1/admin/machinery-operations/maintenance-orders/'.$maintenance->json('data.id').'/complete',
            ['completion_comment' => 'Контрольный осмотр пройден'],
        )->assertOk();

        $canonical->refresh();
        self::assertSame(AssetTechnicalStatus::Serviceable, $canonical->technical_status);
        self::assertSame('serviceable', $canonical->metadata['last_control_inspection']['result']);
    }

    public function test_cutover_flags_hide_unlinked_rows_and_disable_legacy_create_endpoint(): void
    {
        $context = AdminApiTestContext::create();
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();
        MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'asset_code' => 'UNLINKED-LEGACY',
            'name' => 'Legacy only',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 0,
            'meter_hours' => 0,
        ]);
        config()->set('asset_registry.strict_canonical_reads', true);

        self::assertSame(0, app(MachineryAssetReadRepository::class)
            ->paginate((int) $context->organization->id, 20)
            ->total());

        config()->set('asset_registry.legacy_asset_writes_enabled', false);
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/assets', [
                'asset_code' => 'BLOCKED',
                'name' => 'Blocked legacy create',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Создание физической единицы перенесено в единый складской реестр.');
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, fn (MockInterface $mock) => $mock->shouldReceive('hasModuleAccess')->andReturn(true));
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $context = null) => $user->roleAssignments()->where('is_active', true)->get(),
            );
        });
    }
}
