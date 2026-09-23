<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\UserProjectAccessMode;
use App\Models\Organization;
use App\Models\Project;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileFieldFilesService;
use DomainException;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobileFieldFilesServiceTest extends TestCase
{
    public function test_project_file_target_resolves_only_the_selected_organization_project(): void
    {
        $organization = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $resolve = new ReflectionMethod(MobileFieldFilesService::class, 'resolveRecord');
        $service = app(MobileFieldFilesService::class);

        $resolved = $resolve->invoke($service, 'project', $project->id, $project);
        $this->assertSame($project->id, $resolved->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $resolve->invoke($service, 'project', $anotherProject->id, $project);
    }

    public function test_organization_file_permission_does_not_allow_download_from_unassigned_project(): void
    {
        [$organizationId, $user, $project] = $this->actorWithOrganizationFilePermissionButNoProjectAccess();

        $this->expectException(DomainException::class);
        app(MobileFieldFilesService::class)->show($user, $organizationId, 1, (int) $project->id);
    }

    public function test_organization_file_permission_does_not_allow_upload_to_unassigned_project(): void
    {
        [$organizationId, $user, $project] = $this->actorWithOrganizationFilePermissionButNoProjectAccess();

        $this->expectException(DomainException::class);
        app(MobileFieldFilesService::class)->upload($user, $organizationId, [
            'project_id' => (int) $project->id,
        ]);
    }

    /** @return array{int, \App\Models\User, Project} */
    private function actorWithOrganizationFilePermissionButNoProjectAccess(): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $context->organization->users()->updateExistingPivot($context->user->id, [
            'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
        ]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Organization file rights',
            'slug' => 'organization_file_rights_'.$context->organization->id,
            'system_permissions' => ['report_files.view', 'reports.photo_upload'],
            'module_permissions' => [],
            'interface_access' => ['mobile'],
            'conditions' => null,
            'is_active' => true,
            'created_by' => $context->user->id,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $context->user->id,
            'role_slug' => $role->slug,
            'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getOrganizationContext((int) $context->organization->id)->id,
            'assigned_by' => $context->user->id,
            'expires_at' => null,
            'is_active' => true,
        ]);

        $authorization = app(AuthorizationService::class);
        foreach (['report_files.view', 'reports.photo_upload'] as $permission) {
            $this->assertTrue($authorization->can(
                $context->user,
                $permission,
                ['organization_id' => (int) $context->organization->id],
            ));
        }
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->withAnyArgs()->andReturn(true);
        });

        return [(int) $context->organization->id, $context->user, $project];
    }
}
