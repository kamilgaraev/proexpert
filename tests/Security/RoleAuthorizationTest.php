<?php

declare(strict_types=1);

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('organization_owner role grants organization view permission', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $org->id]);

    $context = AuthorizationContext::getOrganizationContext($org->id);

    UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $context->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canView = $authService->can($user, 'organization.view', [
        'organization_id' => $org->id,
    ]);

    expect($canView)->toBeTrue();
});

it('organization_owner role grants projects view through project management module', function () {
    $this->mock(AccessController::class, function (MockInterface $mock): void {
        $mock->shouldReceive('hasModuleAccess')
            ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'project-management');
    });
    app()->forgetInstance(ModulePermissionChecker::class);
    app()->forgetInstance(PermissionResolver::class);
    app()->forgetInstance(AuthorizationService::class);

    $org = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $org->id]);

    $context = AuthorizationContext::getOrganizationContext($org->id);

    UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $context->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canView = $authService->can($user, 'projects.view', [
        'organization_id' => $org->id,
    ]);

    expect($canView)->toBeTrue();
});

it('organization_owner role grants organization search through contractor portal module', function () {
    $this->mock(AccessController::class, function (MockInterface $mock): void {
        $mock->shouldReceive('hasModuleAccess')
            ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'contractor-portal');
    });
    app()->forgetInstance(ModulePermissionChecker::class);
    app()->forgetInstance(PermissionResolver::class);
    app()->forgetInstance(AuthorizationService::class);

    $org = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $org->id]);

    $context = AuthorizationContext::getOrganizationContext($org->id);

    UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $context->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canSearch = $authService->can($user, 'organizations.search', [
        'organization_id' => $org->id,
    ]);

    expect($canSearch)->toBeTrue();
});

it('user without role cannot access admin interface', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $org->id]);

    $authService = app(AuthorizationService::class);

    $canAccessAdmin = $authService->canAccessInterface($user, 'admin');

    expect($canAccessAdmin)->toBeFalse();
});

it('user with organization_owner role can access lk interface', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $org->id]);

    $context = AuthorizationContext::getOrganizationContext($org->id);

    UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $context->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canAccessLk = $authService->canAccessInterface($user, 'lk');

    expect($canAccessLk)->toBeTrue();
});

it('revoked role no longer grants permissions', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $org->id]);

    $context = AuthorizationContext::getOrganizationContext($org->id);

    $assignment = UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $context->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canBefore = $authService->can($user, 'organization.view', [
        'organization_id' => $org->id,
    ]);

    $assignment->update(['is_active' => false]);
    Cache::flush();

    $canAfter = $authService->can($user, 'organization.view', [
        'organization_id' => $org->id,
    ]);

    expect($canBefore)->toBeTrue()
        ->and($canAfter)->toBeFalse();
});

it('custom role in organization A does not grant access in organization B', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $user = User::factory()->create(['current_organization_id' => $orgA->id]);

    $contextA = AuthorizationContext::getOrganizationContext($orgA->id);

    UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $contextA->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canInOrgA = $authService->hasRole($user, 'organization_owner', $contextA->id);
    $canInOrgB = $authService->can($user, 'organization.view', ['organization_id' => $orgB->id]);

    expect($canInOrgA)->toBeTrue()
        ->and($canInOrgB)->toBeFalse();
});
