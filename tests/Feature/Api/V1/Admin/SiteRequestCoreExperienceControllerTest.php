<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestGroup;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Modules\Core\AccessController;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class SiteRequestCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_material_request_group_and_list_only_own_requests(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Concrete mix', 'CONC-SR');
        $foreignRequest = $this->createSiteRequest($foreignContext, Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
        ]));
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/site-requests', [
                'project_id' => $project->id,
                'title' => 'Pour slab materials',
                'description' => 'Materials for morning slab pour',
                'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
                'priority' => 'high',
                'required_date' => now()->addDays(2)->toDateString(),
                'delivery_address' => 'Moscow, Test site',
                'delivery_time_from' => '09:00',
                'delivery_time_to' => '12:00',
                'materials' => [
                    [
                        'material_id' => $material->id,
                        'quantity' => 7.5,
                        'unit' => 'm3',
                        'note' => 'Foundation zone A',
                    ],
                    [
                        'name' => 'Fiber additive',
                        'quantity' => 10,
                        'unit' => 'kg',
                    ],
                ],
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.title', 'Pour slab materials');

        $group = SiteRequestGroup::query()
            ->where('organization_id', $context->organization->id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $requests = SiteRequest::query()
            ->where('organization_id', $context->organization->id)
            ->where('site_request_group_id', $group->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $requests);
        $this->assertSame(SiteRequestStatusEnum::DRAFT, $requests[0]->status);
        $this->assertSame($material->id, $requests[0]->material_id);
        $this->assertSame('Concrete mix', $requests[0]->material_name);
        $this->assertSame('Fiber additive', $requests[1]->material_name);
        $this->assertDatabaseHas('site_request_history', [
            'site_request_id' => $requests[0]->id,
            'user_id' => $context->user->id,
            'action' => 'created',
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/site-requests?per_page=20&request_type=material_request');

        $indexResponse->assertOk();
        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($requests[0]->id, $ids);
        $this->assertContains($requests[1]->id, $ids);
        $this->assertNotContains($foreignRequest->id, $ids);
    }

    public function test_site_request_creation_rejects_foreign_project_and_material_without_mutation(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $ownProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignUnit = $this->createUnit($foreignContext->organization->id, 'Foreign piece', 'fpcs');
        $foreignMaterial = $this->createMaterial(
            $foreignContext->organization->id,
            $foreignUnit->id,
            'Foreign concrete',
            'CONC-F'
        );
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/site-requests', [
                'project_id' => $foreignProject->id,
                'title' => 'Foreign project request',
                'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
                'material_name' => 'Concrete',
                'material_quantity' => 3,
                'material_unit' => 'm3',
            ]);

        $foreignProjectResponse->assertStatus(422);

        $foreignMaterialResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/site-requests', [
                'project_id' => $ownProject->id,
                'title' => 'Foreign material request',
                'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
                'material_id' => $foreignMaterial->id,
                'material_quantity' => 3,
                'material_unit' => 'm3',
            ]);

        $foreignMaterialResponse->assertStatus(422);

        $this->assertDatabaseMissing('site_requests', [
            'organization_id' => $context->organization->id,
        ]);
        $this->assertDatabaseMissing('site_request_groups', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_group_update_rejects_foreign_request_ids_without_replacing_group_items(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Rebar', 'REB-SR');
        $ownGroup = SiteRequestGroup::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Rebar pack',
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
        $ownRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'site_request_group_id' => $ownGroup->id,
            'title' => 'Rebar pack - A500',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => 'medium',
            'material_id' => $material->id,
            'material_name' => 'Rebar A500',
            'material_quantity' => 2,
            'material_unit' => 't',
        ]);
        $foreignRequest = $this->createSiteRequest($foreignContext, $foreignProject);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/site-requests/groups/{$ownGroup->id}", [
                'title' => 'Rebar pack updated',
                'materials' => [
                    [
                        'id' => $foreignRequest->id,
                        'material_id' => $material->id,
                        'quantity' => 4,
                        'unit' => 't',
                    ],
                ],
            ]);

        $updateResponse->assertStatus(422);

        $this->assertDatabaseHas('site_requests', [
            'id' => $ownRequest->id,
            'organization_id' => $context->organization->id,
            'site_request_group_id' => $ownGroup->id,
            'material_quantity' => 2,
            'deleted_at' => null,
        ]);
        $this->assertSame(1, SiteRequest::query()
            ->where('organization_id', $context->organization->id)
            ->where('site_request_group_id', $ownGroup->id)
            ->count());
    }

    public function test_group_update_can_add_catalog_material_to_empty_group_with_default_priority(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Dry mix', 'DRY-MIX');
        $emptyGroup = SiteRequestGroup::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Additional materials',
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/site-requests/groups/{$emptyGroup->id}", [
                'materials' => [
                    [
                        'material_id' => $material->id,
                        'quantity' => 12,
                        'unit' => 'pcs',
                        'note' => 'Add from catalog',
                    ],
                ],
            ]);

        $response->assertOk();

        $createdRequest = SiteRequest::query()
            ->where('organization_id', $context->organization->id)
            ->where('site_request_group_id', $emptyGroup->id)
            ->firstOrFail();

        $this->assertSame(SiteRequestPriorityEnum::MEDIUM, $createdRequest->priority);
        $this->assertSame($material->id, $createdRequest->material_id);
        $this->assertSame('Dry mix', $createdRequest->material_name);
        $this->assertSame('12.000', (string) $createdRequest->material_quantity);
        $this->assertSame('pcs', $createdRequest->material_unit);
    }

    private function createSiteRequest(AdminApiTestContext $context, Project $project): SiteRequest
    {
        return SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Foreign visible request',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => 'medium',
            'material_name' => 'Foreign material',
            'material_quantity' => 1,
            'material_unit' => 'pcs',
        ]);
    }

    private function createUnit(int $organizationId, string $name, string $shortName): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'short_name' => $shortName,
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
    }

    private function createMaterial(int $organizationId, int $unitId, string $name, string $code): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'measurement_unit_id' => $unitId,
            'category' => 'Site requests',
            'default_price' => 100,
            'is_active' => true,
        ]);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'site-requests');
        });
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
                }
            );
        });
    }
}
