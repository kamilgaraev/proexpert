<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecuritySessionsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_own_security_sessions(): void
    {
        $this->withoutMiddleware();
        $user = User::factory()->create();

        UserAuthSession::query()->create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'device'),
            'device_name' => 'Windows, Chrome',
            'ip_address' => '127.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api_landing')
            ->getJson('/api/v1/landing/security/sessions');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.device_name', 'Windows, Chrome');
    }
}
