<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\Notifications\Models\NotificationDevice;
use App\Models\User;
use App\Services\Auth\JwtAuthService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class MobileDeviceTokenRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    public function test_mobile_user_can_register_update_and_remove_multiple_device_tokens(): void
    {
        $this->withoutMiddleware();
        $user = User::factory()->create();
        $first = [
            'installation_id' => 'phone-a',
            'platform' => 'android',
            'provider' => 'rustore',
            'token' => 'rustore-token-a',
        ];

        $this->actingAs($user, 'api_mobile')
            ->postJson('/api/v1/mobile/notifications/devices', $first)
            ->assertOk()
            ->assertJsonPath('data.installation_id', 'phone-a')
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.provider', 'rustore');

        $this->actingAs($user, 'api_mobile')
            ->postJson('/api/v1/mobile/notifications/devices', [
                ...$first,
                'token' => 'rustore-token-a-rotated',
            ])
            ->assertOk();

        $this->actingAs($user, 'api_mobile')
            ->postJson('/api/v1/mobile/notifications/devices', [
                'installation_id' => 'phone-b',
                'platform' => 'android',
                'provider' => 'rustore',
                'token' => 'rustore-token-b',
            ])
            ->assertOk();

        self::assertSame(2, NotificationDevice::query()->where('user_id', $user->id)->count());
        self::assertSame(
            'rustore-token-a-rotated',
            NotificationDevice::query()->where('installation_id', 'phone-a')->firstOrFail()->token,
        );
        self::assertNotSame(
            'rustore-token-a-rotated',
            DB::table('notification_devices')->where('installation_id', 'phone-a')->value('token'),
        );

        $this->actingAs($user, 'api_mobile')
            ->deleteJson('/api/v1/mobile/notifications/devices/phone-a')
            ->assertOk();

        self::assertDatabaseMissing('notification_devices', [
            'user_id' => $user->id,
            'installation_id' => 'phone-a',
        ]);
        self::assertDatabaseHas('notification_devices', [
            'user_id' => $user->id,
            'installation_id' => 'phone-b',
        ]);
    }

    public function test_deleting_a_device_does_not_remove_another_users_registration(): void
    {
        $this->withoutMiddleware();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $payload = ['installation_id' => 'shared-phone', 'platform' => 'android', 'provider' => 'rustore'];

        $this->actingAs($firstUser, 'api_mobile')->postJson('/api/v1/mobile/notifications/devices', [
            ...$payload,
            'token' => 'aaaa1111',
        ])->assertOk();
        $this->actingAs($secondUser, 'api_mobile')->postJson('/api/v1/mobile/notifications/devices', [
            ...$payload,
            'token' => 'bbbb2222',
        ])->assertOk();
        self::assertDatabaseMissing('notification_devices', [
            'user_id' => $firstUser->id,
            'installation_id' => 'shared-phone',
        ]);

        $this->actingAs($firstUser, 'api_mobile')
            ->deleteJson('/api/v1/mobile/notifications/devices/shared-phone')
            ->assertOk();

        self::assertDatabaseHas('notification_devices', [
            'user_id' => $secondUser->id,
            'installation_id' => 'shared-phone',
        ]);
    }

    public function test_registration_requires_provider_and_rejects_provider_platform_mismatch(): void
    {
        $this->withoutMiddleware();
        $user = User::factory()->create();

        $this->actingAs($user, 'api_mobile')
            ->postJson('/api/v1/mobile/notifications/devices', [
                'installation_id' => 'old-client',
                'platform' => 'android',
                'token' => 'legacy-fcm-token',
            ])
            ->assertUnprocessable();

        $this->actingAs($user, 'api_mobile')
            ->postJson('/api/v1/mobile/notifications/devices', [
                'installation_id' => 'invalid-apns-token',
                'platform' => 'ios',
                'provider' => 'rustore',
                'token' => 'token',
            ])
            ->assertUnprocessable();

        $this->actingAs($user, 'api_mobile')
            ->postJson('/api/v1/mobile/notifications/devices', [
                'installation_id' => 'wrong-provider',
                'platform' => 'android',
                'provider' => 'fcm',
                'token' => 'token',
            ])
            ->assertUnprocessable();

        self::assertSame(0, NotificationDevice::query()->where('user_id', $user->id)->count());
    }

    public function test_logout_removes_only_the_current_installation_registration(): void
    {
        $this->withoutMiddleware();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $tokens = app(\App\BusinessModules\Features\Notifications\Services\MobileDeviceTokenService::class);
        $tokens->register($user, 'this-phone', 'android', 'rustore', 'token-this-phone');
        $tokens->register($user, 'other-phone', 'android', 'rustore', 'token-other-phone');
        $tokens->register($otherUser, 'their-phone', 'android', 'rustore', 'token-their-phone');
        $auth = Mockery::mock(JwtAuthService::class);
        $auth->shouldReceive('logout')->once()->with('api_mobile')->andReturn([
            'success' => true,
            'message' => 'ok',
            'status_code' => 200,
        ]);
        $this->app->instance(JwtAuthService::class, $auth);

        $this->actingAs($user, 'api_mobile')
            ->postJson('/api/v1/mobile/auth/logout', ['installation_id' => 'this-phone'])
            ->assertOk();

        self::assertDatabaseMissing('notification_devices', ['user_id' => $user->id, 'installation_id' => 'this-phone']);
        self::assertDatabaseHas('notification_devices', ['user_id' => $user->id, 'installation_id' => 'other-phone']);
        self::assertDatabaseHas('notification_devices', ['user_id' => $otherUser->id, 'installation_id' => 'their-phone']);
    }
}
