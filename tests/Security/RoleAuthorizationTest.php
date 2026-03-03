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

it('organization_owner role grants view estimates permission', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $org->id]);

    $context = AuthorizationContext::firstOrCreate(
        ['type' => AuthorizationContext::TYPE_ORGANIZATION, 'resource_id' => $org->id],
        ['parent_context_id' => null]
    );

    UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $context->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canView = $authService->can($user, 'estimates.view', [
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

    $context = AuthorizationContext::firstOrCreate(
        ['type' => AuthorizationContext::TYPE_ORGANIZATION, 'resource_id' => $org->id],
        ['parent_context_id' => null]
    );

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

    $context = AuthorizationContext::firstOrCreate(
        ['type' => AuthorizationContext::TYPE_ORGANIZATION, 'resource_id' => $org->id],
        ['parent_context_id' => null]
    );

    $assignment = UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $context->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canBefore = $authService->can($user, 'estimates.view', [
        'organization_id' => $org->id,
    ]);

    $assignment->update(['is_active' => false]);
    Cache::flush();

    $canAfter = $authService->can($user, 'estimates.view', [
        'organization_id' => $org->id,
    ]);

    expect($canBefore)->toBeTrue()
        ->and($canAfter)->toBeFalse();
});

it('custom role in organization A does not grant access in organization B', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $user = User::factory()->create(['current_organization_id' => $orgA->id]);

    $contextA = AuthorizationContext::firstOrCreate(
        ['type' => AuthorizationContext::TYPE_ORGANIZATION, 'resource_id' => $orgA->id],
        ['parent_context_id' => null]
    );

    UserRoleAssignment::create([
        'user_id' => $user->id,
        'context_id' => $contextA->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canInOrgA = $authService->hasRole($user, 'organization_owner', $contextA->id);
    $canInOrgB = $authService->can($user, 'estimates.view', ['organization_id' => $orgB->id]);

    expect($canInOrgA)->toBeTrue()
        ->and($canInOrgB)->toBeFalse();
});
