<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\JwtTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
