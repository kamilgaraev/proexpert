<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\BusinessModules\Features\Notifications\Services\NotificationQueryService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class NotificationContourIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_operations_cannot_observe_or_mutate_lk_target(): void
    {
        [$user, $adminNotification, $lkNotification] = $this->fixtures();
        $this->withoutMiddleware();

        $adminList = $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/notifications?interface=lk')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $adminNotification->id)
            ->assertJsonMissing(['id' => $lkNotification->id]);
        $this->assertListResponseShape($adminList, NotificationInterface::Admin);
        self::assertIsInt($adminList->json('data.0.sequence'));
        $countResponse = $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/notifications/unread-count?interface=lk')
            ->assertJsonPath('data.count', 1);
        $this->assertCountResponseShape($countResponse);
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

        $lkList = $this->actingAs($user, 'api_landing')
            ->getJson('/api/v1/landing/notifications?interface=admin')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $lkNotification->id)
            ->assertJsonMissing(['id' => $adminNotification->id]);
        $this->assertListResponseShape($lkList, NotificationInterface::Lk);
        self::assertIsInt($lkList->json('data.0.sequence'));
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

    public function test_filtered_list_meta_contains_global_unread_aggregates_for_trusted_contour(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $system = $this->notification($user, $organization->id, [NotificationInterface::Admin]);
        $procurement = $this->notification($user, $organization->id, [NotificationInterface::Admin]);
        $this->notification($user, $organization->id, [NotificationInterface::Lk]);
        $system->forceFill([
            'notification_type' => 'system',
            'type' => 'system.notice',
            'data' => ['category' => 'system', 'type' => 'system.notice'],
        ])->save();
        $procurement->forceFill([
            'notification_type' => 'procurement',
            'type' => 'purchase_request.created',
            'data' => ['category' => 'procurement', 'type' => 'purchase_request.created'],
        ])->save();
        $this->withoutMiddleware();

        $response = $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/notifications?category=system&interface=lk');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('meta.unread_by_category.system', 1)
            ->assertJsonPath('meta.unread_by_category.procurement', 1)
            ->assertJsonPath('meta.unread_by_notification_type.system', 1)
            ->assertJsonPath('meta.unread_by_notification_type.procurement', 1);
        self::assertGreaterThan(0, $response->json('meta.snapshot_sequence'));
        self::assertSame(1, $response->json('meta.unread_by_type')['system.notice']);
        self::assertSame(1, $response->json('meta.unread_by_type')['purchase_request.created']);
    }

    public function test_list_uses_id_as_stable_tiebreaker_for_equal_creation_time(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $first = $this->notification($user, $organization->id, [NotificationInterface::Admin]);
        $second = $this->notification($user, $organization->id, [NotificationInterface::Admin]);
        $createdAt = now()->startOfSecond();
        $first->forceFill(['created_at' => $createdAt])->save();
        $second->forceFill(['created_at' => $createdAt])->save();
        $expectedIds = [$first->id, $second->id];
        rsort($expectedIds, SORT_STRING);
        $this->withoutMiddleware();

        $response = $this->actingAs($user, 'api_admin')->getJson('/api/v1/admin/notifications');

        $response->assertOk();
        self::assertSame($expectedIds, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_customer_v1_and_legacy_aliases_read_only_customer_targets(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $customer = $this->notification($user, $organization->id, [NotificationInterface::Customer]);
        $admin = $this->notification($user, $organization->id, [NotificationInterface::Admin]);
        $this->withoutMiddleware();

        foreach (['/api/v1/customer/notifications', '/api/customer/notifications'] as $uri) {
            $response = $this->actingAs($user, 'api_landing')->getJson($uri.'?interface=admin');

            $response->assertOk();
            $ids = collect($response->json('data.items'))->pluck('id')->all();
            self::assertContains($customer->id, $ids);
            self::assertNotContains($admin->id, $ids);
            $response->assertJsonPath('data.meta.organization_id', $organization->id);
            $response->assertJsonPath('data.meta.unread_count', 1);
            $response->assertJsonPath('data.meta.filters.interface', 'admin');
            $response->assertJsonPath('data.items.0.isUnread', true);
            $this->assertListResponseShape($response, NotificationInterface::Customer);
            self::assertIsInt($response->json('data.items.0.sequence'));
        }
    }

    public function test_mobile_list_exposes_atomic_snapshot_meta_in_mobile_response_shape(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $notification = $this->notification($user, $organization->id, [NotificationInterface::Mobile]);
        $this->withoutMiddleware();

        $response = $this->actingAs($user, 'api_mobile')->getJson('/api/v1/mobile/notifications');

        $response->assertOk()->assertJsonPath('data.0.id', $notification->id);
        $this->assertListResponseShape($response, NotificationInterface::Mobile);
        self::assertIsInt($response->json('data.0.sequence'));
    }

    public function test_missing_current_organization_exposes_only_global_notifications(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => null]);
        $global = $this->notification($user, null, [NotificationInterface::Admin]);
        $organizationNotification = $this->notification(
            $user,
            $organization->id,
            [NotificationInterface::Admin]
        );
        $this->withoutMiddleware();

        $response = $this->actingAs($user, 'api_admin')->getJson('/api/v1/admin/notifications');

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $ids = collect($response->json('data'))->pluck('id')->all();
        self::assertContains($global->id, $ids);
        self::assertNotContains($organizationNotification->id, $ids);
    }

    public function test_customer_dashboard_query_counts_only_visible_customer_targets(): void
    {
        $organization = Organization::factory()->verified()->create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $current = $this->notification($user, $organization->id, [NotificationInterface::Customer]);
        $global = $this->notification($user, null, [NotificationInterface::Customer]);
        $dismissed = $this->notification($user, $organization->id, [NotificationInterface::Customer]);
        $foreign = $this->notification($user, $foreignOrganization->id, [NotificationInterface::Customer]);
        $admin = $this->notification($user, $organization->id, [NotificationInterface::Admin]);
        $this->target($dismissed, NotificationInterface::Customer)->dismiss();

        $count = app(NotificationQueryService::class)->unreadCountFor(
            $user,
            NotificationInterface::Customer,
            $organization->id
        );

        self::assertSame(2, $count);
        self::assertNull($this->target($current, NotificationInterface::Customer)->fresh()->read_at);
        self::assertNull($this->target($global, NotificationInterface::Customer)->fresh()->read_at);
        self::assertNull($this->target($foreign, NotificationInterface::Customer)->fresh()->read_at);
        self::assertNull($this->target($admin, NotificationInterface::Admin)->fresh()->read_at);
    }

    public function test_customer_aliases_support_show_and_isolated_mutations(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->withoutMiddleware();

        foreach (['/api/v1/customer', '/api/customer'] as $prefix) {
            $notification = $this->notification($user, $organization->id, [NotificationInterface::Customer]);
            $target = $this->target($notification, NotificationInterface::Customer);

            $this->actingAs($user, 'api_landing')
                ->getJson("{$prefix}/notifications/{$notification->id}?interface=admin")
                ->assertOk()
                ->assertJsonPath('data.id', $notification->id);
            $this->actingAs($user, 'api_landing')
                ->patchJson("{$prefix}/notifications/{$notification->id}/read", ['interface' => 'admin'])
                ->assertOk();
            self::assertNotNull($target->fresh()->read_at);
            $this->actingAs($user, 'api_landing')
                ->patchJson("{$prefix}/notifications/{$notification->id}/unread", ['interface' => 'admin'])
                ->assertOk();
            self::assertNull($target->fresh()->read_at);
            $this->actingAs($user, 'api_landing')
                ->deleteJson("{$prefix}/notifications/{$notification->id}")
                ->assertOk();
            self::assertNotNull($target->fresh()->dismissed_at);
        }
    }

    public function test_mark_all_ignores_dismissed_cross_organization_and_other_contour_targets(): void
    {
        $organization = Organization::factory()->verified()->create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $current = $this->notification($user, $organization->id, [NotificationInterface::Customer]);
        $global = $this->notification($user, null, [NotificationInterface::Customer]);
        $dismissed = $this->notification($user, $organization->id, [NotificationInterface::Customer]);
        $foreign = $this->notification($user, $foreignOrganization->id, [NotificationInterface::Customer]);
        $admin = $this->notification($user, $organization->id, [NotificationInterface::Admin]);
        $this->target($dismissed, NotificationInterface::Customer)->dismiss();
        $this->withoutMiddleware();

        $this->actingAs($user, 'api_landing')
            ->postJson('/api/v1/customer/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        self::assertNotNull($this->target($current, NotificationInterface::Customer)->fresh()->read_at);
        self::assertNotNull($this->target($global, NotificationInterface::Customer)->fresh()->read_at);
        self::assertNull($this->target($dismissed, NotificationInterface::Customer)->fresh()->read_at);
        self::assertNull($this->target($foreign, NotificationInterface::Customer)->fresh()->read_at);
        self::assertNull($this->target($admin, NotificationInterface::Admin)->fresh()->read_at);
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

    private function assertListResponseShape(TestResponse $response, NotificationInterface $interface): void
    {
        $rootKeys = array_keys($response->json());

        if ($interface === NotificationInterface::Customer) {
            self::assertEqualsCanonicalizing(['success', 'message', 'data'], $rootKeys);
            self::assertEqualsCanonicalizing(['items', 'meta'], array_keys($response->json('data')));
            self::assertEqualsCanonicalizing(
                ['organization_id', 'unread_count', 'snapshot_sequence', 'total', 'filters'],
                array_keys($response->json('data.meta'))
            );

            return;
        }

        $expectedRootKeys = $interface === NotificationInterface::Mobile
            ? ['success', 'message', 'data', 'meta']
            : ['success', 'message', 'data', 'meta', 'links'];
        self::assertEqualsCanonicalizing($expectedRootKeys, $rootKeys);
        self::assertEqualsCanonicalizing(
            $interface === NotificationInterface::Mobile
                ? [
                    'current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total',
                    'unread_count', 'unread_by_category', 'unread_by_notification_type',
                    'unread_by_type', 'snapshot_sequence', 'links',
                ]
                : [
                    'current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total',
                    'unread_count', 'unread_by_category', 'unread_by_notification_type',
                    'unread_by_type', 'snapshot_sequence',
                ],
            array_keys($response->json('meta'))
        );
    }

    private function assertCountResponseShape(TestResponse $response): void
    {
        self::assertEqualsCanonicalizing(['success', 'message', 'data'], array_keys($response->json()));
        self::assertEqualsCanonicalizing(
            ['count', 'by_category', 'by_notification_type', 'by_type'],
            array_keys($response->json('data'))
        );
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
        $nextSequence = DB::getDriverName() === 'pgsql'
            ? null
            : ((int) NotificationTarget::query()->max('sequence')) + 1;
        $notification->targets()->createMany(array_map(
            static fn (NotificationInterface $interface, int $index): array => [
                'interface' => $interface->value,
                ...($nextSequence === null ? [] : ['sequence' => $nextSequence + $index]),
            ],
            $interfaces,
            array_keys($interfaces)
        ));

        return $notification;
    }

    private function target(Notification $notification, NotificationInterface $interface): NotificationTarget
    {
        return $notification->targets()->where('interface', $interface->value)->firstOrFail();
    }
}
