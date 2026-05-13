<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SecuritySessionsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_can_list_own_security_sessions(): void
    {
        $context = AdminApiTestContext::create();

        $session = UserAuthSession::query()->create([
            'user_id' => $context->user->id,
            'organization_id' => $context->organization->id,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'admin-device'),
            'device_name' => 'macOS, Safari',
            'ip_address' => '10.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeadersForSession($context, $session))
            ->getJson('/api/v1/admin/security/sessions');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.device_name', 'macOS, Safari')
            ->assertJsonPath('data.0.is_current', true);
    }

    public function test_admin_user_can_revoke_other_sessions_but_not_current_session(): void
    {
        $context = AdminApiTestContext::create();

        $currentSession = $this->createSession($context, [
            'session_uuid' => (string) Str::uuid(),
            'device_name' => 'Windows, Chrome',
        ]);
        $otherSession = $this->createSession($context, [
            'session_uuid' => (string) Str::uuid(),
            'device_name' => 'iPhone, Safari',
        ]);
        $foreignContext = AdminApiTestContext::create();
        $foreignSession = $this->createSession($foreignContext, [
            'session_uuid' => (string) Str::uuid(),
            'device_name' => 'Foreign device',
        ]);

        $headers = $this->authHeadersForSession($context, $currentSession);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/admin/security/sessions/{$foreignSession->id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->assertTrue($foreignSession->fresh()->isActive());

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/admin/security/sessions/{$currentSession->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($currentSession->fresh()->isActive());

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/admin/security/sessions/{$otherSession->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($currentSession->fresh()->isActive());
        $this->assertFalse($otherSession->fresh()->isActive());
    }

    public function test_admin_user_can_revoke_all_other_active_sessions(): void
    {
        $context = AdminApiTestContext::create();

        $currentSession = $this->createSession($context, [
            'session_uuid' => (string) Str::uuid(),
            'device_name' => 'Current workstation',
        ]);
        $firstOtherSession = $this->createSession($context, [
            'session_uuid' => (string) Str::uuid(),
            'device_name' => 'Old laptop',
        ]);
        $secondOtherSession = $this->createSession($context, [
            'session_uuid' => (string) Str::uuid(),
            'device_name' => 'Old phone',
        ]);

        $this->withHeaders($this->authHeadersForSession($context, $currentSession))
            ->postJson('/api/v1/admin/security/sessions/revoke-others')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.revoked_count', 2);

        $this->assertTrue($currentSession->fresh()->isActive());
        $this->assertFalse($firstOtherSession->fresh()->isActive());
        $this->assertFalse($secondOtherSession->fresh()->isActive());
    }

    private function createSession(AdminApiTestContext $context, array $attributes = []): UserAuthSession
    {
        return UserAuthSession::query()->create(array_merge([
            'user_id' => $context->user->id,
            'organization_id' => $context->organization->id,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', Str::random(16)),
            'device_name' => 'Test device',
            'ip_address' => '10.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $attributes));
    }

    private function authHeadersForSession(AdminApiTestContext $context, UserAuthSession $session): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::claims([
                'organization_id' => $context->organization->id,
                'session_uuid' => $session->session_uuid,
            ])->fromUser($context->user),
            'Accept' => 'application/json',
        ];
    }
}
