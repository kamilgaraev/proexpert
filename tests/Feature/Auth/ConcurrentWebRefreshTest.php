<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ConcurrentWebRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_immediate_retry_gets_same_rotation_but_late_replay_revokes_session(): void
    {
        config([
            'web_auth.keys.lk' => str_repeat('k', 64),
            'web_auth.refresh_concurrency_window_seconds' => 5,
        ]);
        Cache::clear();
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'is_active' => true,
            'current_organization_id' => $organization->id,
        ]);
        $user->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true]);
        $session = UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'refresh-test-device'),
            'status' => AuthSessionStatus::Active,
            'risk_score' => 0,
            'risk_flags' => [],
            'is_trusted' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class);
        $original = $tokens->issue($user, 'lk', $session->session_uuid, $organization->id, false);
        $payload = $tokens->parse($original->refreshToken, 'lk', 'refresh');

        $first = $tokens->rotate($user, $payload, $original->refreshToken);
        $second = $tokens->rotate($user, $payload, $original->refreshToken);

        self::assertSame($first->accessToken, $second->accessToken);
        self::assertSame($first->refreshToken, $second->refreshToken);
        self::assertSame($first->csrfToken, $second->csrfToken);
        self::assertTrue($session->fresh()->isActive());
        $cached = Cache::get("web_auth:refresh_rotation:lk:{$session->session_uuid}:{$payload->tokenId}");
        self::assertIsString($cached);
        self::assertStringNotContainsString($first->refreshToken, $cached);

        $this->travel(6)->seconds();

        try {
            $tokens->rotate($user, $payload, $original->refreshToken);
            self::fail('Late refresh replay was accepted.');
        } catch (RuntimeException) {
        }

        self::assertFalse($session->fresh()->isActive());
        self::assertSame('refresh_token_replay_or_state_loss', $session->fresh()->revoked_reason);
    }
}
