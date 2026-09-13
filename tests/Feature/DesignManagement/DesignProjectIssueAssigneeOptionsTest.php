<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\UserProjectAccessMode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignProjectIssueAssigneeOptionsTest extends TestCase
{
    public function test_options_are_paginated_scoped_and_available_with_pir_without_quality(): void
    {
        $context = AdminApiTestContext::create(['name' => 'Автор'], roleSlug: 'project_manager');
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create();
        $project->users()->attach($context->user->id, ['role' => 'project_manager', 'is_active' => true]);
        $candidates = [];
        foreach (['active', 'active', 'inactive_org', 'inactive_project', 'other_project', 'foreign_org', 'no_project'] as $index => $state) {
            $user = User::factory()->create(['name' => 'Кандидат '.($index + 1)]);
            $organization = $state === 'foreign_org' ? Organization::factory()->create() : $context->organization;
            $organization->users()->attach($user->id, ['is_active' => $state !== 'inactive_org']);
            if ($state !== 'no_project') {
                ($state === 'other_project' ? $otherProject : $project)->users()->attach($user->id, ['role' => 'member', 'is_active' => $state !== 'inactive_project']);
            }
            $candidates[] = $user;
        }
        $pir = true;
        $review = true;
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('can')->andReturnUsing(static function (User $user, string $permission) use (&$review): bool {
            return match ($permission) {
                'admin.access', 'design-management.view' => true,
                'design-management.review' => $review,
                default => false,
            };
        });
        $authorization->shouldReceive('hasRole')->andReturnTrue();
        $authorization->shouldReceive('getUserRoleSlugs')->andReturn(['project_manager']);
        $authorization->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()->where('is_active', true)->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))->get());
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnUsing(static function (int $organizationId, string $module) use (&$pir): bool {
            return match ($module) {
                'design-management' => $pir,
                'project-management', 'file-management' => true,
                default => false,
            };
        });
        $url = '/api/v1/admin/design-management/projects/'.$project->id.'/issues/assignees';
        $headers = $context->authHeaders();
        $first = $this->getJson($url.'?'.http_build_query(['search' => 'Кандидат', 'per_page' => 1]), $headers)
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 1);
        self::assertSame([['id' => $candidates[0]->id, 'name' => $candidates[0]->name]], $first->json('data'));
        $second = $this->getJson($url.'?'.http_build_query(['search' => 'Кандидат', 'per_page' => 1, 'page' => 2]), $headers)
            ->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.total', 2);
        self::assertSame([['id' => $candidates[1]->id, 'name' => $candidates[1]->name]], $second->json('data'));
        $this->getJson($url.'?'.http_build_query(['search' => 'кандидат 2']), $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $candidates[1]->id);
        foreach ([['per_page' => 101], ['page' => 0], ['search' => str_repeat('a', 101)]] as $invalid) {
            $this->getJson($url.'?'.http_build_query($invalid), $headers)->assertUnprocessable();
        }
        $this->getJson('/api/v1/admin/design-management/projects/'.$otherProject->id.'/issues/assignees', $headers)->assertUnprocessable()->assertJsonPath('data', null);
        $this->getJson('/api/v1/admin/design-management/projects/'.$foreignProject->id.'/issues/assignees', $headers)->assertUnprocessable()->assertJsonPath('data', null);
        $project->users()->updateExistingPivot($context->user->id, ['is_active' => false]);
        $this->getJson($url, $headers)->assertUnprocessable()->assertJsonPath('data', null);
        $project->users()->updateExistingPivot($context->user->id, ['is_active' => true]);
        $review = false;
        $this->getJson($url, $headers)->assertForbidden()->assertJsonPath('data', null);
        $review = true;
        $pir = false;
        $this->getJson($url, $headers)->assertForbidden()->assertJsonPath('data', null);
        $pir = true;
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value]);
        $this->getJson('/api/v1/admin/design-management/projects/'.$otherProject->id.'/issues/assignees', $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $candidates[4]->id);
    }
}
