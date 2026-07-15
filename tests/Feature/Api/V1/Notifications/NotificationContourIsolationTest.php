<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationContourIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_operations_cannot_observe_or_mutate_lk_target(): void
    {
        [$user, $adminNotification, $lkNotification] = $this->fixtures();
        $this->withoutMiddleware();

        $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/notifications?interface=lk')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $adminNotification->id)
            ->assertJsonMissing(['id' => $lkNotification->id]);
        $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/notifications/unread-count?interface=lk')
            ->assertJsonPath('data.count', 1);
        $this->assertForeignOperationsAreNotFound('api/v1/admin', 'api_admin', $user, $lkNotification);

        $this->actingAs($user, 'api_admin')
            ->postJson('/api/v1/admin/notifications/mark-all-read', ['interface' => 'lk'])
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->assertNotNull($this->target($adminNotification, NotificationInterface::Admin)->fresh()->read_at);
        $this->assertNull($this->target($lkNotification, NotificationInterface::Lk)->fresh()->read_at);
    }

    public function test_lk_operations_cannot_observe_or_mutate_admin_target(): void
    {
        [$user, $adminNotification, $lkNotification] = $this->fixtures();
        $this->withoutMiddleware();

        $this->actingAs($user, 'api_landing')
            ->getJson('/api/v1/landing/notifications?interface=admin')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $lkNotification->id)
            ->assertJsonMissing(['id' => $adminNotification->id]);
        $this->actingAs($user, 'api_landing')
            ->getJson('/api/v1/landing/notifications/unread-count?interface=admin')
            ->assertJsonPath('data.count', 1);
        $this->assertForeignOperationsAreNotFound('api/v1/landing', 'api_landing', $user, $adminNotification);

        $this->actingAs($user, 'api_landing')
            ->postJson('/api/v1/landing/notifications/mark-all-read', ['interface' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->assertNotNull($this->target($lkNotification, NotificationInterface::Lk)->fresh()->read_at);
        $this->assertNull($this->target($adminNotification, NotificationInterface::Admin)->fresh()->read_at);
    }

    public function test_current_target_mutations_do_not_change_other_target_or_legacy_state(): void
    {
        [$user, $notification] = $this->fixtures(sharedNotification: true);
        $this->withoutMiddleware();

        $this->actingAs($user, 'api_admin')
            ->patchJson("/api/v1/admin/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonMissingPath('data.targets')
            ->assertJsonMissingPath('data.websocket_status');
        $this->assertNotNull($this->target($notification, NotificationInterface::Admin)->fresh()->read_at);
        $this->assertNull($this->target($notification, NotificationInterface::Lk)->fresh()->read_at);
        $this->assertNull($notification->fresh()->read_at);

        $this->actingAs($user, 'api_admin')
            ->patchJson("/api/v1/admin/notifications/{$notification->id}/unread")
            ->assertOk();
        $this->assertNull($this->target($notification, NotificationInterface::Admin)->fresh()->read_at);

        $this->actingAs($user, 'api_admin')
            ->deleteJson("/api/v1/admin/notifications/{$notification->id}")
            ->assertOk();
        $this->assertNotNull($this->target($notification, NotificationInterface::Admin)->fresh()->dismissed_at);
        $this->assertNull($this->target($notification, NotificationInterface::Lk)->fresh()->dismissed_at);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);

        $this->actingAs($user, 'api_landing')
            ->getJson("/api/v1/landing/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);
    }

    public function test_notifications_are_limited_to_current_organization_or_global(): void
    {
        [$user, $adminNotification] = $this->fixtures();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreign = $this->notification($user, $foreignOrganization->id, [NotificationInterface::Admin]);
        $global = $this->notification($user, null, [NotificationInterface::Admin]);
        $this->withoutMiddleware();

        $response = $this->actingAs($user, 'api_admin')->getJson('/api/v1/admin/notifications');

        $response->assertOk()->assertJsonPath('meta.total', 2);
        $ids = collect($response->json('data'))->pluck('id')->all();
        self::assertContains($adminNotification->id, $ids);
        self::assertContains($global->id, $ids);
        self::assertNotContains($foreign->id, $ids);
    }

    public function test_customer_v1_and_legacy_aliases_read_only_customer_targets(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $customer = $this->notification($user, $organization->id, [NotificationInterface::Customer]);
        $admin = $this->notification($user, $organization->id, [NotificationInterface::Admin]);
        $this->withoutMiddleware();

        foreach (['/api/v1/customer/notifications', '/api/customer/notifications'] as $uri) {
            $response = $this->actingAs($user, 'api_landing')->getJson($uri);

            $response->assertOk();
            $ids = collect($response->json('data.items'))->pluck('id')->all();
            self::assertContains($customer->id, $ids);
            self::assertNotContains($admin->id, $ids);
            $response->assertJsonPath('data.meta.organization_id', $organization->id);
            $response->assertJsonPath('data.meta.unread_count', 1);
            $response->assertJsonPath('data.items.0.isUnread', true);
        }
    }

    private function assertForeignOperationsAreNotFound(
        string $prefix,
        string $guard,
        User $user,
        Notification $foreign
    ): void {
        $this->actingAs($user, $guard)->getJson("/{$prefix}/notifications/{$foreign->id}")->assertNotFound();
        $this->actingAs($user, $guard)->patchJson("/{$prefix}/notifications/{$foreign->id}/read")->assertNotFound();
        $this->actingAs($user, $guard)->patchJson("/{$prefix}/notifications/{$foreign->id}/unread")->assertNotFound();
        $this->actingAs($user, $guard)->deleteJson("/{$prefix}/notifications/{$foreign->id}")->assertNotFound();
    }

    private function fixtures(bool $sharedNotification = false): array
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);

        if ($sharedNotification) {
            return [$user, $this->notification(
                $user,
                $organization->id,
                [NotificationInterface::Admin, NotificationInterface::Lk]
            )];
        }

        return [
            $user,
            $this->notification($user, $organization->id, [NotificationInterface::Admin]),
            $this->notification($user, $organization->id, [NotificationInterface::Lk]),
        ];
    }

    private function notification(User $user, ?int $organizationId, array $interfaces): Notification
    {
        $notification = Notification::query()->create([
            'type' => 'system.notice',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'organization_id' => $organizationId,
            'notification_type' => 'system',
            'priority' => 'normal',
            'channels' => ['in_app'],
            'delivery_status' => ['in_app' => 'delivered'],
            'data' => ['title' => 'Test notification'],
            'metadata' => [],
            'read_at' => null,
        ]);
        $notification->targets()->createMany(array_map(
            static fn (NotificationInterface $interface): array => ['interface' => $interface->value],
            $interfaces
        ));

        return $notification;
    }

    private function target(Notification $notification, NotificationInterface $interface): NotificationTarget
    {
        return $notification->targets()->where('interface', $interface->value)->firstOrFail();
    }
}
