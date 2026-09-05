<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class LandingNotificationPreferencesAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_login_supplies_organization_and_removed_membership_loses_access(): void
    {
        Notification::fake();
        Queue::fake();
        Cache::clear();
        config(['web_auth.keys.lk' => str_repeat('l', 64)]);
        $origin = (string) config('web_auth.origins.lk.0');
        $this->withHeaders(['Origin' => $origin, 'Idempotency-Key' => 'notification-preferences-registration'])
            ->postJson('/api/v1/landing/auth/register', [
                'name' => 'Notification Owner',
                'email' => 'notification-preferences@example.test',
                'password' => 'Password1',
                'password_confirmation' => 'Password1',
                'organization_name' => 'Notification Test Organization',
                'terms_accepted' => true,
                'privacy_accepted' => true,
            ])->assertCreated();
        $user = User::where('email', 'notification-preferences@example.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();
        $login = $this->postJson('/api/v1/landing/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])->assertOk();
        $token = $login->json('data.token');
        $this->assertIsString($token);
        $this->withToken($token)->getJson('/api/v1/landing/notifications/preferences')
            ->assertOk()->assertJsonPath('success', true);
        $this->putJson('/api/v1/landing/notifications/preferences', [
            'notification_type' => 'marketing',
            'enabled_channels' => [],
        ])->assertOk();
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'notification_type' => 'marketing',
        ]);
        $user->organizations()->detach($user->current_organization_id);
        $this->getJson('/api/v1/landing/notifications/preferences')->assertForbidden();
    }
}
