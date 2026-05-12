<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuthSecurityEventType;
use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Models\UserSecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
