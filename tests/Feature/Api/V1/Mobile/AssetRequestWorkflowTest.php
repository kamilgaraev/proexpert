<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AssetRequestWorkflowTest extends TestCase
{
    public function test_foreman_creates_project_scoped_asset_request_with_audit_event(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/mobile/machinery-operations/asset-requests', [
            'project_id' => $project->id,
            'planned_start_at' => now()->addHour()->toIso8601String(),
            'planned_end_at' => now()->addHours(9)->toIso8601String(),
            'purpose' => 'Планировка площадки',
            'priority' => 'high',
            'required_profile' => ['operational_mode' => 'shift_operation'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.events.0.event_type', 'requested');
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, fn (MockInterface $mock) => $mock->shouldReceive('hasModuleAccess')->andReturn(true));
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $authorizationContext = null) => $user->roleAssignments()->where('is_active', true)->get(),
            );
        });
    }
}
