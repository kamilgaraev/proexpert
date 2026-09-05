<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Http\Requests\Api\V1\Landing\NotificationPreferencesRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingNotificationPreferencesHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_organization_preferences_can_be_saved_and_read(): void
    {
        $this->prepareAccount();
        $this->putJson('/api/v1/landing/notifications/preferences', [
            'notification_type' => 'marketing',
            'enabled_channels' => [],
        ])->assertOk()->assertJsonPath('success', true);

        $response = $this->getJson('/api/v1/landing/notifications/preferences')->assertOk();
        $item = collect($response->json('data.items'))->firstWhere('notification_type', 'marketing');
        $this->assertSame([], $item['enabled_channels']);
    }

    public function test_body_cannot_override_actor_or_organization(): void
    {
        $this->prepareAccount();
        $this->putJson('/api/v1/landing/notifications/preferences', [
            'notification_type' => 'marketing',
            'enabled_channels' => [],
            'organization_id' => 999999,
            'user_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['organization_id', 'user_id']);
    }

    public function test_channels_are_validated(): void
    {
        $this->prepareAccount();
        $this->putJson('/api/v1/landing/notifications/preferences', [
            'notification_type' => 'marketing',
            'enabled_channels' => ['unknown'],
        ])->assertUnprocessable();
    }

    public function test_routes_keep_landing_authentication_and_session_guards(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v1.landing.notifications.preferences.index');
        $this->assertNotNull($route);
        foreach (['auth:api_landing', 'auth.jwt:api_landing', 'auth.session', 'organization.context', 'interface:lk'] as $guard) {
            $this->assertContains($guard, $route->gatherMiddleware());
        }
    }

    private function prepareAccount(): void
    {
        $this->withoutMiddleware();
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization->id, ['is_active' => true]);
        $this->actingAs($user, 'api_landing');
        $this->app->resolving(NotificationPreferencesRequest::class, static function (NotificationPreferencesRequest $request) use ($organization): void {
            $request->attributes->set('current_organization_id', $organization->id);
        });
    }
}
