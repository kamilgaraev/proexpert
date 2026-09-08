<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCreationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseCreationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_central_warehouse_can_be_created_without_a_project(): void
    {
        $context = AdminApiTestContext::create();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses', [
                'name' => 'Центральный склад',
                'code' => 'CENTRAL',
                'warehouse_type' => 'central',
                'project_id' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.project_id', null);

        $this->assertDatabaseHas('organization_warehouses', [
            'organization_id' => $context->organization->id,
            'code' => 'CENTRAL',
            'project_id' => null,
        ]);
    }

    public function test_project_warehouse_saves_the_selected_organization_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses', [
                'name' => 'Склад объекта',
                'code' => 'PROJECT',
                'warehouse_type' => 'project',
                'project_id' => $project->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('organization_warehouses', [
            'organization_id' => $context->organization->id,
            'code' => 'PROJECT',
            'project_id' => $project->id,
        ]);
    }

    public function test_project_is_required_and_validation_returns_422_instead_of_500(): void
    {
        $context = AdminApiTestContext::create();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses', [
                'name' => 'Склад объекта',
                'code' => 'NO-PROJECT',
                'warehouse_type' => 'project',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->assertDatabaseMissing('organization_warehouses', ['code' => 'NO-PROJECT']);
    }

    public function test_foreign_and_deleted_projects_cannot_be_attached(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $deletedProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $deletedProject->delete();

        foreach ([$foreignProject->id, $deletedProject->id] as $projectId) {
            $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/warehouses', [
                    'name' => 'Недопустимый объект',
                    'code' => 'INVALID-'.$projectId,
                    'warehouse_type' => 'project',
                    'project_id' => $projectId,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('project_id');

            $this->assertDatabaseMissing('organization_warehouses', ['code' => 'INVALID-'.$projectId]);
        }
    }

    public function test_central_warehouse_rejects_an_unexpected_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses', [
                'name' => 'Центральный склад',
                'code' => 'CENTRAL-PROJECT',
                'warehouse_type' => 'central',
                'project_id' => $project->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');
    }

    public function test_service_independently_rejects_a_foreign_project(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $actor = User::query()->where('current_organization_id', $context->organization->id)->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->app->make(WarehouseCreationService::class)->create($actor, [
            'name' => 'Склад',
            'code' => 'SERVICE-FOREIGN',
            'warehouse_type' => 'project',
            'project_id' => $project->id,
        ]);
    }
}
