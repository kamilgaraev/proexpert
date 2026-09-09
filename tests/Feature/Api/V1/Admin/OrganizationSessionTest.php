<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\UserAuthSession;
use App\Services\Auth\OrganizationSessionService;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class OrganizationSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_rotates_session_and_preserves_other_devices_for_admin_and_lk(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can', 'canAccessInterface')->andReturn(true);
        });
        foreach (['admin', 'lk'] as $audience) {
            $context = AdminApiTestContext::create();
            $target = Organization::factory()->verified()->create();
            $target->users()->attach($context->user->id, ['is_active' => true]);
            $tokens = app(WebAuthTokenService::class);
            $original = $tokens->parse($context->token, 'admin', 'access');
            $pair = $tokens->issue($context->user, $audience, $original->sessionUuid, $context->organization->id, false);
            $otherDevice = UserAuthSession::query()->where('session_uuid', $original->sessionUuid)->firstOrFail()->replicate();
            $otherDevice->session_uuid = (string) \Illuminate\Support\Str::uuid();
            $otherDevice->save();
            $prefix = $audience === 'admin' ? '/api/v1/admin' : '/api/v1/landing';
            $origin = $audience === 'admin' ? 'https://admin.1мост.рф' : 'https://lk.1мост.рф';
            $headers = ['Authorization' => 'Bearer '.$pair->accessToken, 'Origin' => $origin, 'X-CSRF-Token' => $pair->csrfToken];
            $this->withHeaders($headers)->getJson($prefix.'/auth/organizations')->assertOk()->assertJsonCount(2, 'data.organizations');
            $response = $this->withHeaders($headers)->postJson($prefix.'/auth/organization', ['organization_id' => $target->id])->assertOk();
            $next = $tokens->parse($response->json('data.token'), $audience, 'access');
            $this->assertSame($target->id, $next->organizationId);
            $this->assertNotSame($original->sessionUuid, $next->sessionUuid);
            $this->assertFalse(UserAuthSession::query()->where('session_uuid', $original->sessionUuid)->firstOrFail()->isActive());
            $this->assertTrue($otherDevice->fresh()->isActive());
            $this->withHeaders($headers)->getJson($prefix.'/auth/organizations')->assertUnauthorized();
            $this->withHeaders(['Authorization' => 'Bearer '.$response->json('data.token')])
                ->getJson($prefix.'/auth/organizations')->assertOk();
        }
    }

    public function test_switch_rejects_inactive_foreign_memberships_and_missing_csrf(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can', 'canAccessInterface')->andReturn(true);
        });
        $context = AdminApiTestContext::create();
        $target = Organization::factory()->verified()->create();
        $target->users()->attach($context->user->id, ['is_active' => false]);
        $foreign = Organization::factory()->verified()->create();
        $tokens = app(WebAuthTokenService::class);
        $payload = $tokens->parse($context->token, 'admin', 'access');
        $pair = $tokens->issue($context->user, 'admin', $payload->sessionUuid, $context->organization->id, false);
        $headers = ['Authorization' => 'Bearer '.$pair->accessToken, 'Origin' => 'https://admin.1мост.рф'];
        $this->withHeaders($headers)->postJson('/api/v1/admin/auth/organization', ['organization_id' => $target->id])->assertForbidden();
        $headers['X-CSRF-Token'] = $pair->csrfToken;
        foreach ([$target, $foreign] as $organization) {
            $this->withHeaders($headers)->postJson('/api/v1/admin/auth/organization', ['organization_id' => $organization->id])->assertForbidden();
        }
        $this->assertTrue(UserAuthSession::query()->where('session_uuid', $payload->sessionUuid)->firstOrFail()->isActive());
    }

    public function test_admin_choices_require_interface_permission_in_each_organization(): void
    {
        $context = AdminApiTestContext::create();
        $target = Organization::factory()->verified()->create();
        $target->users()->attach($context->user->id, ['is_active' => true]);
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($context): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(static fn ($user, $permission, $scope): bool =>
                ($scope['organization_id'] ?? null) === $context->organization->id);
        });
        $choices = app(OrganizationSessionService::class)->choices($context->user, 'admin');
        $this->assertSame([$context->organization->id], array_column($choices, 'id'));
    }
}
