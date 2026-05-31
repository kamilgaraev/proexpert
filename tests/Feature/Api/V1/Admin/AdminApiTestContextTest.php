<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
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

it('resolves workforce permissions through workforce management package access', function (): void {
    /** @var \Tests\TestCase $this */
    $context = AdminApiTestContext::create();

    Cache::flush();

    app()->instance(AccessController::class, \Mockery::mock(AccessController::class, function (MockInterface $mock) use ($context): void {
        $mock->shouldReceive('hasModuleAccess')
            ->withArgs(static fn (int $organizationId, string $module): bool => $organizationId === $context->organization->id)
            ->andReturnUsing(static fn (int $organizationId, string $module): bool => $module === 'workforce-management');
    }));

    app()->forgetInstance(ModulePermissionChecker::class);
    app()->forgetInstance(PermissionResolver::class);
    app()->forgetInstance(AuthorizationService::class);

    expect(app(AuthorizationService::class)->can($context->user, 'workforce.view', [
        'context_type' => 'organization',
        'organization_id' => $context->organization->id,
    ]))->toBeTrue();
});

it('organization owner resolves workforce permissions through workforce management package access', function (): void {
    /** @var \Tests\TestCase $this */
    $context = AdminApiTestContext::create(roleSlug: 'organization_owner');

    Cache::flush();

    app()->instance(AccessController::class, \Mockery::mock(AccessController::class, function (MockInterface $mock) use ($context): void {
        $mock->shouldReceive('hasModuleAccess')
            ->withArgs(static fn (int $organizationId, string $module): bool => $organizationId === $context->organization->id)
            ->andReturnUsing(static fn (int $organizationId, string $module): bool => $module === 'workforce-management');
    }));

    app()->forgetInstance(ModulePermissionChecker::class);
    app()->forgetInstance(PermissionResolver::class);
    app()->forgetInstance(AuthorizationService::class);

    expect(app(AuthorizationService::class)->can($context->user, 'workforce.view', [
        'context_type' => 'organization',
        'organization_id' => $context->organization->id,
    ]))->toBeTrue();
});

it('organization owner resolves mdm permissions through catalog management package access', function (): void {
    /** @var \Tests\TestCase $this */
    $context = AdminApiTestContext::create(roleSlug: 'organization_owner');

    Cache::flush();

    app()->instance(AccessController::class, \Mockery::mock(AccessController::class, function (MockInterface $mock) use ($context): void {
        $mock->shouldReceive('hasModuleAccess')
            ->withArgs(static fn (int $organizationId, string $module): bool => $organizationId === $context->organization->id)
            ->andReturnUsing(static fn (int $organizationId, string $module): bool => $module === 'catalog-management');
    }));

    app()->forgetInstance(ModulePermissionChecker::class);
    app()->forgetInstance(PermissionResolver::class);
    app()->forgetInstance(AuthorizationService::class);

    expect(app(AuthorizationService::class)->can($context->user, 'mdm.view', [
        'context_type' => 'organization',
        'organization_id' => $context->organization->id,
    ]))->toBeTrue();
});

it('organization owner resolves contractor marketplace permissions through contractor portal package access', function (): void {
    /** @var \Tests\TestCase $this */
    $context = AdminApiTestContext::create(roleSlug: 'organization_owner');

    Cache::flush();

    app()->instance(AccessController::class, \Mockery::mock(AccessController::class, function (MockInterface $mock) use ($context): void {
        $mock->shouldReceive('hasModuleAccess')
            ->withArgs(static fn (int $organizationId, string $module): bool => $organizationId === $context->organization->id)
            ->andReturnUsing(static fn (int $organizationId, string $module): bool => $module === 'contractor-portal');
    }));

    app()->forgetInstance(ModulePermissionChecker::class);
    app()->forgetInstance(PermissionResolver::class);
    app()->forgetInstance(AuthorizationService::class);

    expect(app(AuthorizationService::class)->can($context->user, 'contractor_marketplace.profile.view', [
        'context_type' => 'organization',
        'organization_id' => $context->organization->id,
    ]))->toBeTrue();
});
