<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Customer\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Customer\Auth\CustomerAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class CustomerWebAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_refresh_and_logout_use_memory_access_token_and_host_refresh_cookie(): void
    {
        $user = $this->verifiedCustomer();

        $login = $this->customerRequest()->postJson('/api/v1/customer/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ]);

        $login
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'csrf_token', 'expires_in']]);
        $accessToken = (string) $login->json('data.token');
        $csrfToken = (string) $login->json('data.csrf_token');
        $cookieName = (string) config('web_auth.cookies.customer.name');
        $cookie = collect($login->headers->getCookies())
            ->first(static fn ($candidate): bool => $candidate->getName() === $cookieName);

        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('strict', strtolower((string) $cookie->getSameSite()));
        self::assertStringNotContainsString($cookie->getValue(), $login->getContent());
        self::assertSame(
            (int) $user->id,
            app(WebAuthTokenService::class)->parse($cookie->getValue(), 'customer', 'refresh')->userId,
        );

        $refresh = $this->call(
            'POST',
            '/api/v1/customer/auth/refresh',
            cookies: [$cookieName => $cookie->getValue()],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => (string) config('web_auth.origins.customer.0'),
                'HTTP_X_CSRF_TOKEN' => $csrfToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: '{}',
        );
        $refresh->assertOk()->assertJsonStructure(['data' => ['token', 'csrf_token', 'expires_in']]);
        $nextAccessToken = (string) $refresh->json('data.token');
        $nextCsrfToken = (string) $refresh->json('data.csrf_token');

        $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.customer.0'),
            'Authorization' => 'Bearer '.$nextAccessToken,
            'X-CSRF-Token' => $nextCsrfToken,
        ])->postJson('/api/v1/customer/auth/logout')->assertOk();

        $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.customer.0'),
            'Authorization' => 'Bearer '.$accessToken,
        ])->getJson('/api/v1/customer/profile')->assertUnauthorized();
    }

    public function test_customer_routes_reject_legacy_bearer_and_inactive_membership(): void
    {
        $user = $this->verifiedCustomer();
        $legacy = app(CustomerAuthService::class)->authenticate(
            LoginDTO::fromRequest(['email' => $user->email, 'password' => 'Password1']),
            'api_landing',
        );

        $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.customer.0'),
            'Authorization' => 'Bearer '.(string) $legacy['token'],
        ])->getJson('/api/v1/customer/profile')->assertUnauthorized();

        $login = $this->customerRequest()->postJson('/api/v1/customer/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])->assertOk();
        $user->organizations()->updateExistingPivot($user->current_organization_id, ['is_active' => false]);

        $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.customer.0'),
            'Authorization' => 'Bearer '.(string) $login->json('data.token'),
        ])->getJson('/api/v1/customer/profile')->assertForbidden();
    }

    public function test_refresh_revokes_session_after_membership_is_deactivated(): void
    {
        $user = $this->verifiedCustomer();
        $login = $this->customerRequest()->postJson('/api/v1/customer/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])->assertOk();
        $cookieName = (string) config('web_auth.cookies.customer.name');
        $cookie = collect($login->headers->getCookies())
            ->first(static fn ($candidate): bool => $candidate->getName() === $cookieName);
        self::assertNotNull($cookie);
        $payload = app(WebAuthTokenService::class)->parse($cookie->getValue(), 'customer', 'refresh');
        $user->organizations()->updateExistingPivot($user->current_organization_id, ['is_active' => false]);

        $response = $this->call(
            'POST',
            '/api/v1/customer/auth/refresh',
            cookies: [$cookieName => $cookie->getValue()],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => (string) config('web_auth.origins.customer.0'),
                'HTTP_X_CSRF_TOKEN' => (string) $login->json('data.csrf_token'),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: '{}',
        );

        $response->assertForbidden()->assertJsonPath('code', 'organization_membership_inactive');
        self::assertSame(
            AuthSessionStatus::Revoked,
            UserAuthSession::query()->where('session_uuid', $payload->sessionUuid)->value('status'),
        );
    }

    public function test_customer_mutating_auth_route_without_origin_uses_customer_error_contract(): void
    {
        $this->postJson('/api/v1/customer/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'Password1',
        ])->assertForbidden()->assertExactJson([
            'success' => false,
            'message' => trans_message('auth.access_denied'),
            'data' => null,
            'code' => 'http_403',
        ]);
    }

    public function test_customer_auth_routes_use_customer_origin_refresh_cookie_and_csrf_stack(): void
    {
        $routes = app('router')->getRoutes();
        $login = $routes->match(request()->create('/api/v1/customer/auth/login', 'POST'));
        $refresh = $routes->match(request()->create('/api/v1/customer/auth/refresh', 'POST'));
        $logout = $routes->match(request()->create('/api/v1/customer/auth/logout', 'POST'));

        self::assertContains('origin.web:customer', $login->gatherMiddleware());
        self::assertContains('auth.web-refresh:customer', $refresh->gatherMiddleware());
        self::assertContains('origin.web:customer', $refresh->gatherMiddleware());
        self::assertContains('csrf.web:customer', $refresh->gatherMiddleware());
        self::assertContains('auth.web:customer', $logout->gatherMiddleware());
        self::assertContains('csrf.web:customer', $logout->gatherMiddleware());
        self::assertNotContains('auth.jwt:api_landing', $refresh->gatherMiddleware());
        self::assertNotContains('auth:api_landing', $logout->gatherMiddleware());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::clear();
        config([
            'web_auth.keys.customer' => str_repeat('c', 64),
            'web_auth.cookies.customer.name' => '__Host-most-customer-refresh',
        ]);
    }

    private function verifiedCustomer(): User
    {
        $result = app(CustomerAuthService::class)->register(RegisterDTO::fromRequest([
            'name' => 'Customer Owner',
            'email' => 'customer-web-auth@example.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Customer Web Auth Organization',
        ]));
        self::assertTrue($result['success']);
        $user = User::query()->where('email', 'customer-web-auth@example.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function customerRequest(): self
    {
        return $this->withHeader('Origin', (string) config('web_auth.origins.customer.0'));
    }
}
