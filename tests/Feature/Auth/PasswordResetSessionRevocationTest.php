<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PasswordResetSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_consumes_token_revokes_sessions_and_clears_web_refresh_state(): void
    {
        $user = User::factory()->create(['password' => Hash::make('PreviousPassword1')]);
        $session = UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => null,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'password-reset-device'),
            'device_name' => 'Windows, Chrome',
            'user_agent' => 'Mozilla/5.0 Chrome',
            'ip_address' => '127.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'is_trusted' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $token = Password::broker('users')->createToken($user);

        Cache::put("web_auth:refresh:lk:{$session->session_uuid}", ['token_hash' => 'test'], now()->addHour());
        Cache::put("web_auth:refresh:admin:{$session->session_uuid}", ['token_hash' => 'test'], now()->addHour());

        $resetUser = app(PasswordResetService::class)->reset([
            'email' => $user->email,
            'token' => $token,
            'password' => 'ReplacementPassword1',
        ]);

        self::assertInstanceOf(User::class, $resetUser);
        self::assertTrue(Hash::check('ReplacementPassword1', $user->fresh()->password));
        self::assertSame(AuthSessionStatus::Revoked, $session->fresh()->status);
        self::assertFalse(Password::broker('users')->tokenExists($user, $token));
        self::assertNull(Cache::get("web_auth:refresh:lk:{$session->session_uuid}"));
        self::assertNull(Cache::get("web_auth:refresh:admin:{$session->session_uuid}"));
    }

    public function test_reset_token_cannot_be_consumed_twice(): void
    {
        $user = User::factory()->create(['password' => Hash::make('PreviousPassword1')]);
        $token = Password::broker('users')->createToken($user);
        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'ReplacementPassword1',
        ];

        self::assertInstanceOf(User::class, app(PasswordResetService::class)->reset($payload));
        self::assertNull(app(PasswordResetService::class)->reset($payload));
    }
}
