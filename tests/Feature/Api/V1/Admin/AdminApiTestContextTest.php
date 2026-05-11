<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use Tests\Support\AdminApiTestContext;

it('creates an admin user with organization role and admin access', function (): void {
    $context = AdminApiTestContext::create();

    $authorizationContext = AuthorizationContext::getOrganizationContext($context->organization->id);

    expect(UserRoleAssignment::query()
        ->where('user_id', $context->user->id)
        ->where('role_slug', 'web_admin')
        ->where('context_id', $authorizationContext->id)
        ->where('is_active', true)
        ->exists())->toBeTrue();

    expect(app(AuthorizationService::class)->can($context->user, 'admin.access', [
        'context_type' => 'organization',
        'organization_id' => $context->organization->id,
    ]))->toBeTrue();
});

it('authenticates admin api requests with generated bearer headers', function (): void {
    /** @var \Tests\TestCase $this */
    $context = AdminApiTestContext::create();

    $response = $this
        ->withHeaders($context->authHeaders())
        ->getJson('/api/v1/admin/auth/me');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $context->user->id);
});
