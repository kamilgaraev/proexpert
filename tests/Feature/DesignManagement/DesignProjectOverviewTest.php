<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignProjectOverviewQuery;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Project;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AdminApiTestContext;
use Tests\Support\DesignManagementTestEntitlements;
use Tests\TestCase;

final class DesignProjectOverviewTest extends TestCase
{
    public function test_overview_aggregates_all_stages_without_changing_packages(): void
    {
        $actor = $this->context();
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $other = Project::factory()->create(['organization_id' => $actor->organization->id]);
        foreach (['survey' => ['issued'], 'pd' => ['draft', 'approved'], 'rd' => ['draft', 'issued', 'archived'], 'bim' => ['draft']] as $stage => $statuses) {
            foreach ($statuses as $status) {
                $this->package($actor, $project, $stage, $status);
            }
        }
        $this->package($actor, $other, 'rd', 'draft');
        $rd = DesignPackage::query()->where('project_id', $project->id)->where('project_stage', 'rd')->where('status', 'draft')->sole();
        foreach (['Замечание 1', 'Замечание 2'] as $body) {
            $rd->reviewComments()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'author_id' => $actor->user->id, 'severity' => 'blocking', 'status' => 'open', 'body' => $body]);
        }
        $snapshot = DesignPackage::query()->orderBy('id')->get()->map(fn ($package) => $package->getAttributes())->all();
        $result = app(DesignProjectOverviewQuery::class)->overview($actor->organization->id, $project->id, $actor->user);
        self::assertSame($project->name, $result['project']['name']);
        self::assertSame(['packages_count' => 7, 'issued_packages_count' => 2, 'archived_packages_count' => 1, 'open_packages_count' => 4, 'blocked_packages_count' => 1], $result['totals']);
        $stages = collect($result['stages'])->keyBy('project_stage');
        self::assertSame(3, $stages['rd']['packages_count']);
        self::assertSame(1, $stages['rd']['blocked_packages_count']);
        self::assertSame(2, $stages['pd']['open_packages_count']);
        self::assertSame(1, $stages['bim']['packages_count']);
        self::assertSame($snapshot, DesignPackage::query()->orderBy('id')->get()->map(fn ($package) => $package->getAttributes())->all());
        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/projects/{$project->id}/overview?project_stage=rd&per_page=1")
            ->assertOk()->assertJsonPath('data.totals.packages_count', 7)->assertJsonCount(4, 'data.stages');
    }

    public function test_empty_accessible_project_has_all_zero_stage_buckets(): void
    {
        $actor = $this->context();
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $result = app(DesignProjectOverviewQuery::class)->overview($actor->organization->id, $project->id, $actor->user);
        self::assertCount(4, $result['stages']);
        self::assertSame(0, $result['totals']['packages_count']);
        foreach ($result['stages'] as $stage) {
            self::assertSame(0, $stage['packages_count']);
        }
    }

    public function test_active_participant_can_view_shared_project_with_only_own_organization_packages(): void
    {
        $actor = $this->context();
        $owner = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $owner->organization->id]);
        $project->organizations()->attach($actor->organization->id, [
            'role' => 'contractor', 'role_new' => 'contractor', 'is_active' => true,
            'added_by_user_id' => $owner->user->id,
        ]);
        $this->package($actor, $project, 'rd', 'draft');
        $this->package($owner, $project, 'pd', 'issued');
        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/projects/{$project->id}/overview")
            ->assertOk()->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.totals.packages_count', 1)->assertJsonPath('data.totals.issued_packages_count', 0);
        $project->organizations()->updateExistingPivot($actor->organization->id, ['is_active' => false]);
        Cache::store('array')->flush();
        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/projects/{$project->id}/overview")
            ->assertNotFound();
    }

    public function test_overview_denies_hidden_and_foreign_projects_at_service_and_http_boundaries(): void
    {
        $actor = $this->context();
        $allowed = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $hidden = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $foreign = Project::factory()->create();
        $actor->organization->users()->updateExistingPivot($actor->user->id, ['is_owner' => false, 'project_access_mode' => 'assigned_projects']);
        $allowed->users()->attach($actor->user->id, ['role' => 'project_manager', 'is_active' => true]);
        $this->package($actor, $hidden, 'rd', 'draft');
        self::assertSame(0, app(DesignProjectOverviewQuery::class)->overview($actor->organization->id, $allowed->id, $actor->user)['totals']['packages_count']);
        foreach ([$hidden, $foreign] as $project) {
            try {
                app(DesignProjectOverviewQuery::class)->overview($actor->organization->id, $project->id, $actor->user);
                self::fail('An inaccessible project must not expose its overview.');
            } catch (DomainException $exception) {
                self::assertSame(trans_message('design_management.errors.project_not_found'), $exception->getMessage());
            }
            $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/projects/{$project->id}/overview")->assertNotFound();
        }
    }

    private function context(): AdminApiTestContext
    {
        $actor = AdminApiTestContext::create(roleSlug: 'project_manager');
        DesignManagementTestEntitlements::activate($actor);
        $actor->organization->users()->updateExistingPivot($actor->user->id, ['is_owner' => false, 'project_access_mode' => 'all_projects']);
        $actor->user->roleAssignments()->delete();
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $actor->organization->id, 'name' => 'Обзор ПИР', 'slug' => 'pir-overview-'.$actor->user->id,
            'system_permissions' => ['admin.access'], 'module_permissions' => ['design-management' => ['design-management.view']],
            'interface_access' => ['admin'], 'is_active' => true, 'created_by' => $actor->user->id,
        ]);
        UserRoleAssignment::assignRole($actor->user, $role->slug, AuthorizationContext::getOrganizationContext($actor->organization->id), UserRoleAssignment::TYPE_CUSTOM);
        Cache::store('array')->flush();
        return $actor;
    }

    private function package(AdminApiTestContext $actor, Project $project, string $stage, string $status): DesignPackage
    {
        return DesignPackage::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'title' => 'Комплект', 'project_stage' => $stage, 'status' => $status]);
    }
}
