<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\JwtTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class AuthTokenIssuerContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuer_adds_session_uuid_to_token_when_sessions_are_enabled(): void
    {
        config(['auth_tokens.sessions.enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $token = app(JwtTokenIssuer::class)->issue($user, [
            'guard' => 'api_landing',
            'organization_id' => null,
        ]);

        $payload = JWTAuth::setToken($token)->getPayload();
        $sessionUuid = $payload->get('session_uuid');

        $this->assertNotEmpty($sessionUuid);
        $this->assertDatabaseHas('user_auth_sessions', [
            'user_id' => $user->id,
            'session_uuid' => $sessionUuid,
            'status' => AuthSessionStatus::Active->value,
        ]);
    }

    public function test_issuer_preserves_context_claims(): void
    {
        config(['auth_tokens.sessions.enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();

        $token = app(JwtTokenIssuer::class)->issue($user, [
            'guard' => 'api_brigade',
            'organization_id' => (int) $organization->id,
            'brigade_id' => 41,
        ]);

        $payload = JWTAuth::setToken($token)->getPayload();

        $this->assertSame($organization->id, $payload->get('organization_id'));
        $this->assertSame(41, $payload->get('brigade_id'));
        $this->assertNotEmpty($payload->get('session_uuid'));
    }

    public function test_issuer_reuses_existing_session_uuid_without_creating_session(): void
    {
        config(['auth_tokens.sessions.enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $session = UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => null,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'desktop-chrome'),
            'device_name' => 'Windows, Chrome',
            'user_agent' => 'Mozilla/5.0 Chrome',
            'ip_address' => '127.0.0.1',
            'risk_score' => 10,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'is_trusted' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $token = app(JwtTokenIssuer::class)->issue($user, [
            'guard' => 'api_mobile',
            'organization_id' => 25,
            'session_uuid' => $session->session_uuid,
        ]);

        $payload = JWTAuth::setToken($token)->getPayload();

        $this->assertSame(25, $payload->get('organization_id'));
        $this->assertSame($session->session_uuid, $payload->get('session_uuid'));
        $this->assertDatabaseCount('user_auth_sessions', 1);
    }

    public function test_issuer_ignores_invalid_session_uuid_and_creates_session(): void
    {
        config(['auth_tokens.sessions.enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $token = app(JwtTokenIssuer::class)->issue($user, [
            'guard' => 'api_mobile',
            'organization_id' => null,
            'session_uuid' => 'invalid-session-uuid',
        ]);

        $payload = JWTAuth::setToken($token)->getPayload();
        $sessionUuid = $payload->get('session_uuid');

        $this->assertNotSame('invalid-session-uuid', $sessionUuid);
        $this->assertNotEmpty($sessionUuid);
        $this->assertDatabaseHas('user_auth_sessions', [
            'user_id' => $user->id,
            'session_uuid' => $sessionUuid,
            'status' => AuthSessionStatus::Active->value,
        ]);
    }

    public function test_issuer_ignores_unknown_valid_session_uuid_and_creates_session(): void
    {
        config(['auth_tokens.sessions.enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $unknownSessionUuid = (string) Str::uuid();

        $token = app(JwtTokenIssuer::class)->issue($user, [
            'guard' => 'api_mobile',
            'organization_id' => null,
            'session_uuid' => $unknownSessionUuid,
        ]);

        $payload = JWTAuth::setToken($token)->getPayload();
        $sessionUuid = $payload->get('session_uuid');

        $this->assertNotSame($unknownSessionUuid, $sessionUuid);
        $this->assertDatabaseHas('user_auth_sessions', [
            'user_id' => $user->id,
            'session_uuid' => $sessionUuid,
            'status' => AuthSessionStatus::Active->value,
        ]);
    }
}
