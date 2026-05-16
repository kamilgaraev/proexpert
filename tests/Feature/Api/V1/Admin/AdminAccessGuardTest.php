<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\Support\AdminApiTestContext;
use Tymon\JWTAuth\Facades\JWTAuth;

it('returns a normalized admin response when an admin viewer tries to create a project', function (): void {
    /** @var \Tests\TestCase $this */
    $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');

    $response = $this
        ->withHeaders($context->authHeaders())
        ->postJson('/api/v1/admin/projects', [
            'name' => 'Project without create permission',
        ]);

    $response
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', trans_message('errors.unauthorized'))
        ->assertJsonStructure([
            'success',
            'message',
            'error',
        ]);
});

it('uses the organization id from jwt claims as admin request context', function (): void {
    /** @var \Tests\TestCase $this */
    $firstOrganization = Organization::factory()->verified()->create();
    $secondOrganization = Organization::factory()->verified()->create();

    $firstProject = Project::factory()->create([
        'organization_id' => $firstOrganization->id,
        'name' => 'First organization project',
    ]);

    $secondProject = Project::factory()->create([
        'organization_id' => $secondOrganization->id,
        'name' => 'Second organization project',
    ]);

    $user = User::factory()->create([
        'current_organization_id' => $firstOrganization->id,
    ]);

    foreach ([$firstOrganization, $secondOrganization] as $organization) {
        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
            'settings' => null,
        ]);

        UserRoleAssignment::assignRole(
            user: $user,
            roleSlug: 'web_admin',
            context: AuthorizationContext::getOrganizationContext($organization->id)
        );
    }

    $token = JWTAuth::claims([
        'organization_id' => $secondOrganization->id,
    ])->fromUser($user);

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])
        ->getJson('/api/v1/admin/available-projects');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.projects.0.id', $secondProject->id)
        ->assertJsonPath('data.projects.0.name', $secondProject->name)
        ->assertJsonPath('data.totals.all', 1);

    expect($response->json('data.projects'))->not->toContain(fn (array $project): bool => $project['id'] === $firstProject->id);
});

it('rejects an admin request when jwt organization does not belong to the user', function (): void {
    /** @var \Tests\TestCase $this */
    $ownedOrganization = Organization::factory()->verified()->create();
    $foreignOrganization = Organization::factory()->verified()->create();

    Project::factory()->create([
        'organization_id' => $ownedOrganization->id,
        'name' => 'Owned organization project',
    ]);

    $user = User::factory()->create([
        'current_organization_id' => $ownedOrganization->id,
    ]);

    $ownedOrganization->users()->attach($user->id, [
        'is_owner' => true,
        'is_active' => true,
        'settings' => null,
    ]);

    UserRoleAssignment::assignRole(
        user: $user,
        roleSlug: 'web_admin',
        context: AuthorizationContext::getOrganizationContext($ownedOrganization->id)
    );

    $token = JWTAuth::claims([
        'organization_id' => $foreignOrganization->id,
    ])->fromUser($user);

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])
        ->getJson('/api/v1/admin/available-projects');

    $response
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

it('allows a custom admin role to view projects but not create them', function (): void {
    /** @var \Tests\TestCase $this */
    $organization = Organization::factory()->verified()->create();
    $creator = User::factory()->create(['current_organization_id' => $organization->id]);
    $user = User::factory()->create(['current_organization_id' => $organization->id]);

    $organization->users()->attach($user->id, [
        'is_owner' => false,
        'is_active' => true,
        'settings' => null,
    ]);

    Project::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Visible project for custom role',
    ]);

    $role = OrganizationCustomRole::createRole(
        organizationId: $organization->id,
        name: 'Admin project viewer',
        systemPermissions: ['admin.projects.view'],
        interfaceAccess: ['admin'],
        createdBy: $creator
    );

    $context = AuthorizationContext::getOrganizationContext($organization->id);
    UserRoleAssignment::assignRole(
        user: $user,
        roleSlug: $role->slug,
        context: $context,
        roleType: UserRoleAssignment::TYPE_CUSTOM
    );

    $token = JWTAuth::claims([
        'organization_id' => $organization->id,
    ])->fromUser($user);

    $headers = [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ];

    $this
        ->withHeaders($headers)
        ->getJson('/api/v1/admin/projects')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this
        ->withHeaders($headers)
        ->postJson('/api/v1/admin/projects', [
            'name' => 'Project blocked for custom viewer',
        ])
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

it('does not allow assigning a custom role to a different organization context', function (): void {
    $sourceOrganization = Organization::factory()->verified()->create();
    $targetOrganization = Organization::factory()->verified()->create();
    $creator = User::factory()->create(['current_organization_id' => $sourceOrganization->id]);
    $targetUser = User::factory()->create(['current_organization_id' => $targetOrganization->id]);

    $role = OrganizationCustomRole::createRole(
        organizationId: $sourceOrganization->id,
        name: 'Source organization role',
        systemPermissions: ['profile.view'],
        interfaceAccess: ['lk'],
        createdBy: $creator
    );

    $targetContext = AuthorizationContext::getOrganizationContext($targetOrganization->id);

    app(CustomRoleService::class)->assignRoleToUser(
        role: $role,
        user: $targetUser,
        context: $targetContext,
        assignedBy: $creator
    );
})->throws(\InvalidArgumentException::class);

it('does not allow low level custom role assignment outside the role organization', function (): void {
    $sourceOrganization = Organization::factory()->verified()->create();
    $targetOrganization = Organization::factory()->verified()->create();
    $creator = User::factory()->create(['current_organization_id' => $sourceOrganization->id]);
    $targetUser = User::factory()->create(['current_organization_id' => $targetOrganization->id]);

    $role = OrganizationCustomRole::createRole(
        organizationId: $sourceOrganization->id,
        name: 'Low level source role',
        systemPermissions: ['profile.view'],
        interfaceAccess: ['lk'],
        createdBy: $creator
    );

    app(AuthorizationService::class)->assignRole(
        user: $targetUser,
        roleSlug: $role->slug,
        context: AuthorizationContext::getOrganizationContext($targetOrganization->id),
        roleType: UserRoleAssignment::TYPE_CUSTOM,
        assignedBy: $creator
    );
})->throws(\InvalidArgumentException::class);
