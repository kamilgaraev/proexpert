<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use Tests\Support\AdminApiTestContext;

it('allows an admin user with web admin role to login', function (): void {
    /** @var \Tests\TestCase $this */
    $context = AdminApiTestContext::create([
        'email' => 'admin-smoke@example.com',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => $context->user->email,
        'password' => 'password',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $context->user->id)
        ->assertJsonPath('data.token_type', 'bearer')
        ->assertJsonStructure([
            'data' => [
                'token',
                'expires_in',
            ],
        ]);
});

it('denies admin login for a user without admin access', function (): void {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create([
        'email' => 'no-admin-access@example.com',
        'current_organization_id' => null,
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

it('does not promote a regular organization member to owner during admin login', function (): void {
    /** @var \Tests\TestCase $this */
    $organization = Organization::factory()->verified()->create();
    $user = User::factory()->create([
        'email' => 'regular-member-no-admin@example.com',
        'current_organization_id' => $organization->id,
    ]);

    $organization->users()->attach($user->id, [
        'is_owner' => false,
        'is_active' => true,
        'settings' => null,
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertForbidden()
        ->assertJsonPath('success', false);

    expect(UserRoleAssignment::query()
        ->where('user_id', $user->id)
        ->where('role_slug', 'organization_owner')
        ->where('context_id', AuthorizationContext::getOrganizationContext($organization->id)->id)
        ->exists())->toBeFalse();
});

it('returns an admin api error contract when bearer token is missing', function (): void {
    /** @var \Tests\TestCase $this */
    $response = $this->getJson('/api/v1/admin/auth/me');

    $response
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonStructure([
            'success',
            'message',
        ]);
});
