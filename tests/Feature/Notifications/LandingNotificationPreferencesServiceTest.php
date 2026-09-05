<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\BusinessModules\Features\Notifications\Models\NotificationPreference;
use App\BusinessModules\Features\Notifications\Services\LandingNotificationPreferencesService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingNotificationPreferencesServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_are_isolated_between_organizations_and_empty_selection_persists(): void
    {
        $user = User::factory()->create();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user->organizations()->attach([$first->id => ['is_active' => true], $second->id => ['is_active' => true]]);
        $service = app(LandingNotificationPreferencesService::class);

        $service->update($user, $first->id, 'marketing', []);
        $service->update($user, $second->id, 'marketing', ['email']);

        $firstItems = collect($service->read($user, $first->id)['items']);
        $secondItems = collect($service->read($user, $second->id)['items']);
        $this->assertSame([], $firstItems->firstWhere('notification_type', 'marketing')['enabled_channels']);
        $this->assertSame(['email'], $secondItems->firstWhere('notification_type', 'marketing')['enabled_channels']);
        $this->assertSame(2, NotificationPreference::where('user_id', $user->id)->count());
    }

    public function test_foreign_organization_cannot_be_read(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->expectException(AuthorizationException::class);

        app(LandingNotificationPreferencesService::class)->read($user, $organization->id);
    }

    public function test_inactive_membership_cannot_change_preferences(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization->id, ['is_active' => false]);
        $this->expectException(AuthorizationException::class);

        app(LandingNotificationPreferencesService::class)->update($user, $organization->id, 'marketing', []);
    }

    public function test_mandatory_type_cannot_be_disabled(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization->id, ['is_active' => true]);
        $this->expectException(AuthorizationException::class);

        app(LandingNotificationPreferencesService::class)->update($user, $organization->id, 'transactional', []);
    }
}
