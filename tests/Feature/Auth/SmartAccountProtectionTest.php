<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuthSecurityEventType;
use App\Enums\AuthSessionStatus;
use App\DTOs\Auth\LoginDTO;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Models\UserSecurityEvent;
use App\Services\Auth\AuthRiskService;
use App\Services\Auth\DeviceFingerprintService;
use App\Services\Auth\UserAuthSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartAccountProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_auth_sessions_and_security_events(): void
    {
        $user = User::factory()->create();

        $session = UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => null,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'desktop-chrome'),
            'device_name' => 'Windows, Chrome',
            'user_agent' => 'Mozilla/5.0 Chrome',
            'ip_address' => '127.0.0.1',
            'risk_score' => 30,
            'risk_flags' => ['new_device'],
            'status' => AuthSessionStatus::Active,
            'is_trusted' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $event = UserSecurityEvent::query()->create([
            'user_id' => $user->id,
            'organization_id' => null,
            'auth_session_id' => $session->id,
            'type' => AuthSecurityEventType::NewDeviceLogin,
            'risk_score' => 30,
            'risk_flags' => ['new_device'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 Chrome',
            'metadata' => ['device_name' => 'Windows, Chrome'],
        ]);

        $this->assertTrue($user->authSessions()->whereKey($session->id)->exists());
        $this->assertTrue($user->securityEvents()->whereKey($event->id)->exists());
        $this->assertSame(AuthSessionStatus::Active, $session->fresh()->status);
        $this->assertSame(AuthSecurityEventType::NewDeviceLogin, $event->fresh()->type);
        $this->assertTrue($session->fresh()->isActive());
    }

    public function test_device_fingerprint_is_stable_for_same_request_profile(): void
    {
        $service = app(DeviceFingerprintService::class);

        $request = Request::create('/api/v1/landing/auth/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0',
            'HTTP_ACCEPT_LANGUAGE' => 'ru-RU,ru;q=0.9',
        ]);
        $request->headers->set('X-Device-Fingerprint', 'client-fp-1');

        $first = $service->fingerprint($request);
        $second = $service->fingerprint($request);

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
        $this->assertSame('Windows, Chrome', $service->deviceName($request));
    }

    public function test_risk_service_scores_new_device_and_multiple_recent_ips(): void
    {
        $user = User::factory()->create();

        UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => null,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'known-device'),
            'device_name' => 'Windows, Chrome',
            'ip_address' => '10.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        UserSecurityEvent::query()->create([
            'user_id' => $user->id,
            'type' => AuthSecurityEventType::LoginSuccess,
            'risk_score' => 0,
            'risk_flags' => [],
            'ip_address' => '10.0.0.2',
            'metadata' => [],
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        UserSecurityEvent::query()->create([
            'user_id' => $user->id,
            'type' => AuthSecurityEventType::LoginSuccess,
            'risk_score' => 0,
            'risk_flags' => [],
            'ip_address' => '10.0.0.3',
            'metadata' => [],
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $request = Request::create('/api/v1/landing/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.4',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 iPhone Safari',
        ]);

        $result = app(AuthRiskService::class)->score($user, $request, 'new-device');

        $this->assertGreaterThanOrEqual(55, $result['score']);
        $this->assertContains('new_device', $result['flags']);
        $this->assertContains('many_recent_ips', $result['flags']);
    }

    public function test_auth_session_service_creates_event_and_session(): void
    {
        config(['auth_tokens.sessions.notify_new_device' => false]);

        $user = User::factory()->create();
        $request = Request::create('/api/v1/landing/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome',
        ]);

        $session = app(UserAuthSessionService::class)->createForLogin($user, null, $request);

        $this->assertDatabaseHas('user_auth_sessions', [
            'id' => $session->id,
            'user_id' => $user->id,
            'status' => AuthSessionStatus::Active->value,
        ]);

        $this->assertDatabaseHas('user_security_events', [
            'user_id' => $user->id,
            'auth_session_id' => $session->id,
            'type' => AuthSecurityEventType::NewDeviceLogin->value,
        ]);
    }

    public function test_revoke_others_keeps_current_session_active(): void
    {
        $user = User::factory()->create();
        $current = UserAuthSession::query()->create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'current'),
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $other = UserAuthSession::query()->create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'other'),
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        app(UserAuthSessionService::class)
            ->revokeOtherSessions($user, $current->session_uuid, 'manual_revoke_others');

        $this->assertTrue($current->fresh()->isActive());
        $this->assertFalse($other->fresh()->isActive());
    }

    public function test_new_device_login_sends_notification(): void
    {
        Notification::fake();
        config(['auth_tokens.sessions.notify_new_device' => true]);

        $user = User::factory()->create();
        $request = Request::create('/api/v1/landing/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome',
        ]);

        app(UserAuthSessionService::class)->createForLogin($user, null, $request);

        Notification::assertSentTo(
            $user,
            \App\Notifications\NewDeviceLoginNotification::class
        );
    }

    public function test_authenticate_adds_session_uuid_to_jwt(): void
    {
        Notification::fake();
        config(['auth_tokens.sessions.enabled' => true]);

        $user = User::factory()->create([
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $result = app(\App\Services\Auth\JwtAuthService::class)->authenticate(
            new LoginDTO($user->email, 'password'),
            'api_landing'
        );

        $this->assertTrue($result['success']);

        $payload = \Tymon\JWTAuth\Facades\JWTAuth::setToken($result['token'])->getPayload();
        $sessionUuid = $payload->get('session_uuid');

        $this->assertNotEmpty($sessionUuid);
        $this->assertDatabaseHas('user_auth_sessions', [
            'user_id' => $user->id,
            'session_uuid' => $sessionUuid,
            'status' => AuthSessionStatus::Active->value,
        ]);
    }

    public function test_auth_session_middleware_allows_missing_claim_when_not_enforced(): void
    {
        config(['auth_tokens.sessions.enforce' => false]);
        $user = User::factory()->create();
        $request = Request::create('/api/v1/landing/auth/me', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = app(\App\Http\Middleware\EnsureAuthSessionIsActive::class)
            ->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_auth_session_middleware_rejects_revoked_session_when_enforced(): void
    {
        config(['auth_tokens.sessions.enforce' => true]);
        $user = User::factory()->create();
        $session = UserAuthSession::query()->create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'revoked'),
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Revoked,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'revoked_at' => now(),
            'revoked_reason' => 'test',
        ]);

        $request = Request::create('/api/v1/landing/auth/me', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('token_payload', new class ($session->session_uuid) {
            public function __construct(private readonly string $sessionUuid)
            {
            }

            public function get(string $key): ?string
            {
                return $key === 'session_uuid' ? $this->sessionUuid : null;
            }
        });

        $response = app(\App\Http\Middleware\EnsureAuthSessionIsActive::class)
            ->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_missing_session_uuid_is_rejected_when_enforcement_enabled(): void
    {
        config(['auth_tokens.sessions.enforce' => true]);
        $user = User::factory()->create();
        $request = Request::create('/api/v1/landing/auth/me', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('token_payload', new class {
            public function get(string $key): ?string
            {
                return null;
            }
        });

        $response = app(\App\Http\Middleware\EnsureAuthSessionIsActive::class)
            ->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }
}
