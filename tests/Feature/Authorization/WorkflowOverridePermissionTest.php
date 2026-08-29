<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

final class WorkflowOverridePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_owner_receives_workflow_override_from_workflow_module(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'workflow-management');
        });
        $this->app->forgetInstance(ModulePermissionChecker::class);
        $this->app->forgetInstance(PermissionResolver::class);
        $this->app->forgetInstance(AuthorizationService::class);

        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $context = AuthorizationContext::getOrganizationContext($organization->id);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'context_id' => $context->id,
            'role_slug' => 'organization_owner',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'is_active' => true,
        ]);

        $this->assertTrue(app(AuthorizationService::class)->can($user, 'workflow.override', [
            'organization_id' => $organization->id,
        ]));
    }
}
