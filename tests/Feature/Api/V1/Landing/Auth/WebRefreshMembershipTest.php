<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class WebRefreshMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_lk_refresh_revokes_session_after_membership_is_removed(): void
    {
        $origin = (string) config('web_auth.origins.lk.0');
        $this->withHeaders([
            'Origin' => $origin,
            'Idempotency-Key' => 'lk-refresh-membership-registration',
        ])->postJson('/api/v1/landing/auth/register', [
            'name' => 'LK Refresh Owner',
            'email' => 'lk-refresh-membership@example.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'LK Refresh Organization',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ])->assertCreated();
        $user = User::query()->where('email', 'lk-refresh-membership@example.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        $login = $this->withHeader('Origin', $origin)->postJson('/api/v1/landing/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])->assertOk();
        $cookieName = (string) config('web_auth.cookies.lk.name');
        $cookie = collect($login->headers->getCookies())
            ->first(static fn ($candidate): bool => $candidate->getName() === $cookieName);
        self::assertNotNull($cookie);
        $payload = app(WebAuthTokenService::class)->parse($cookie->getValue(), 'lk', 'refresh');
        $user->organizations()->detach($user->current_organization_id);

        $response = $this->call(
            'POST',
            '/api/v1/landing/auth/refresh',
            cookies: [$cookieName => $cookie->getValue()],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => $origin,
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

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Cache::clear();
        config(['web_auth.keys.lk' => str_repeat('l', 64)]);
    }
}
