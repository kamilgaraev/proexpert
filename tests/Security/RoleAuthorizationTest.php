<?php

declare(strict_types=1);

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

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
