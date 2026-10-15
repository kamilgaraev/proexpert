<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Services\Project\UserProjectAccessService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\Support\DesignManagementTestEntitlements;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class ProjectRolePermissionIsolationSecurityTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    public static function scopeFlags(): array
    {
        return [[[]], [['strict_project_scope' => false]], [['strict_project_scope' => true]]];
    }

    public function test_http_contract_update_cannot_borrow_sibling_project_manager_role(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $first, $second, $firstContract, $secondContract] = $this->fixture();
        $authorization = app(AuthorizationService::class);
        self::assertFalse($authorization->canCurrent($actor->user, 'contracts.edit', ['organization_id' => $actor->organization->id]));
        self::assertFalse($authorization->canCurrent($actor->user, 'contracts.edit', [
            'organization_id' => $actor->organization->id, 'project_id' => $second->id, 'strict_project_scope' => true,
        ]));
        self::assertTrue(app(UserProjectAccessService::class)->canAccessCurrentOrganizationProject($actor->user, $second));

        $this->putJson($this->url($first, $firstContract), ['subject' => 'Allowed project edit'], $actor->authHeaders())->assertOk();
        self::assertSame('Allowed project edit', $firstContract->fresh()->subject);
        $before = $secondContract->fresh()->getRawOriginal();
        $this->putJson($this->url($second, $secondContract), ['subject' => 'Denied sibling edit'], $actor->authHeaders())->assertForbidden();
        self::assertSame($before, $secondContract->fresh()->getRawOriginal());
    }

    #[DataProvider('scopeFlags')]
    public function test_cached_and_current_permissions_never_inherit_sibling_project_roles(array $flags): void
    {
        [$actor, $first, $second] = $this->fixture();
        $authorization = app(AuthorizationService::class);
        $organization = ['organization_id' => $actor->organization->id];
        $firstContext = $organization + ['project_id' => $first->id] + $flags;
        $secondContext = $organization + ['project_id' => $second->id] + $flags;

        self::assertTrue($authorization->can($actor->user, 'contracts.edit', $firstContext));
        self::assertFalse($authorization->can($actor->user, 'contracts.edit', $secondContext));
        self::assertTrue($authorization->canCurrent($actor->user, 'contracts.edit', $firstContext));
        self::assertFalse($authorization->canCurrent($actor->user, 'contracts.edit', $secondContext));
        self::assertFalse($authorization->canCurrent($actor->user, 'contracts.edit', $organization));
    }

    public function test_organization_permission_still_inherits_into_the_target_project(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, , $second, , $contract] = $this->fixture();
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $actor->organization->id, 'name' => 'Organization contract editor',
            'created_by' => $actor->user->id, 'system_permissions' => ['admin.access'],
            'module_permissions' => ['contract-management' => ['contracts.edit']],
            'interface_access' => ['admin'], 'is_active' => true,
        ]);
        UserRoleAssignment::assignRole($actor->user, $role->slug,
            AuthorizationContext::getOrganizationContext($actor->organization->id), UserRoleAssignment::TYPE_CUSTOM);

        self::assertTrue(app(AuthorizationService::class)->canCurrent($actor->user, 'contracts.edit', [
            'organization_id' => $actor->organization->id, 'project_id' => $second->id,
        ]));
        $this->putJson($this->url($second, $contract), ['subject' => 'Allowed organization edit'], $actor->authHeaders())->assertOk();
        self::assertSame('Allowed organization edit', $contract->fresh()->subject);
    }

    private function fixture(): array
    {
        $actor = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $actor->user->organizations()->updateExistingPivot($actor->organization->id, [
            'is_owner' => false, 'project_access_mode' => 'assigned_projects',
        ]);
        DesignManagementTestEntitlements::activate($actor);
        $projects = [];
        $contracts = [];
        $contractor = Contractor::query()->create([
            'organization_id' => $actor->organization->id, 'name' => 'Role isolation contractor',
            'inn' => '770000000001', 'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        foreach (['project_manager', 'project_viewer'] as $role) {
            $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
            $project->users()->attach($actor->user->id, ['role' => 'member', 'is_active' => true]);
            UserRoleAssignment::assignRole($actor->user, $role,
                AuthorizationContext::getProjectContext($project->id, $actor->organization->id));
            $projects[] = $project;
            $contracts[] = Contract::query()->create([
                'organization_id' => $actor->organization->id, 'project_id' => $project->id,
                'contractor_id' => $contractor->id, 'contract_side_type' => 'contract',
                'number' => 'ROLE-SCOPE-'.$project->id, 'date' => '2026-10-15',
                'subject' => 'Original subject', 'base_amount' => 1000, 'total_amount' => 1000,
                'status' => 'active', 'is_fixed_amount' => true, 'is_multi_project' => false, 'is_self_execution' => false,
            ]);
        }

        return [$actor, $projects[0], $projects[1], $contracts[0], $contracts[1]];
    }

    private function url(Project $project, Contract $contract): string
    {
        return '/api/v1/admin/projects/'.$project->id.'/contracts/'.$contract->id;
    }
}
