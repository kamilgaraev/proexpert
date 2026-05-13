<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class AdminBaseExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_show_and_update_affect_only_authenticated_user(): void
    {
        $context = AdminApiTestContext::create([
            'email_verified_at' => now(),
            'name' => 'Old Admin Name',
            'phone' => '+70000000000',
        ]);
        $otherUser = User::factory()->create([
            'name' => 'Untouched User',
            'email' => 'untouched.profile@gmail.com',
            'phone' => '+79999999999',
            'password' => Hash::make('old-password'),
        ]);
        $this->allowAdminAccess(['web_admin']);

        $showResponse = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/profile');

        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.id', $context->user->id);
        $showResponse->assertJsonPath('data.name', 'Old Admin Name');

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->patchJson('/api/v1/admin/profile', [
                'name' => 'Updated Admin Name',
                'email' => 'updated.profile.admin@gmail.com',
                'phone' => '+71111111111',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.id', $context->user->id);
        $updateResponse->assertJsonPath('data.name', 'Updated Admin Name');
        $updateResponse->assertJsonPath('data.email', 'updated.profile.admin@gmail.com');

        $updatedUser = $context->user->fresh();
        $otherUser = $otherUser->fresh();

        $this->assertSame('Updated Admin Name', $updatedUser->name);
        $this->assertSame('updated.profile.admin@gmail.com', $updatedUser->email);
        $this->assertSame('+71111111111', $updatedUser->phone);
        $this->assertNull($updatedUser->email_verified_at);
        $this->assertTrue(Hash::check('new-secure-password', $updatedUser->password));
        $this->assertSame('Untouched User', $otherUser->name);
        $this->assertSame('untouched.profile@gmail.com', $otherUser->email);
        $this->assertTrue(Hash::check('old-password', $otherUser->password));
    }

    public function test_onboarding_flag_is_validated_and_saved_for_current_user(): void
    {
        $context = AdminApiTestContext::create([
            'has_completed_onboarding' => false,
        ]);
        $this->allowAdminAccess(['web_admin']);

        $invalidResponse = $this->withHeaders($context->authHeaders())
            ->patchJson('/api/v1/admin/profile/onboarding', []);

        $invalidResponse->assertUnprocessable();
        $invalidResponse->assertJsonPath('success', false);
        $this->assertFalse((bool) $context->user->fresh()->has_completed_onboarding);

        $validResponse = $this->withHeaders($context->authHeaders())
            ->patchJson('/api/v1/admin/profile/onboarding', [
                'has_completed_onboarding' => true,
            ]);

        $validResponse->assertOk();
        $validResponse->assertJsonPath('success', true);
        $this->assertTrue((bool) $context->user->fresh()->has_completed_onboarding);
    }

    public function test_notifications_are_scoped_to_authenticated_user_for_listing_and_counters(): void
    {
        $context = AdminApiTestContext::create();
        $otherUser = $this->createOrganizationMember($context->organization);
        $this->allowAdminAccess(['web_admin']);

        $ownUnread = $this->createNotification($context->organization, $context->user, [
            'type' => 'contract',
            'notification_type' => 'contracts',
            'priority' => 'high',
            'data' => [
                'title' => 'Contract attention',
                'category' => 'contracts',
                'type' => 'contract',
                'project_id' => '101',
            ],
        ]);
        $ownRead = $this->createNotification($context->organization, $context->user, [
            'notification_type' => 'materials',
            'read_at' => now(),
            'data' => [
                'title' => 'Material read',
                'category' => 'materials',
                'type' => 'material',
            ],
        ]);
        $foreignUnread = $this->createNotification($context->organization, $otherUser, [
            'type' => 'contract',
            'notification_type' => 'contracts',
            'priority' => 'critical',
            'data' => [
                'title' => 'Foreign contract',
                'category' => 'contracts',
                'type' => 'contract',
            ],
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/notifications?per_page=10');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 2);

        $listedIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($ownUnread->id, $listedIds);
        $this->assertContains($ownRead->id, $listedIds);
        $this->assertNotContains($foreignUnread->id, $listedIds);

        $unreadResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/notifications/unread-count');

        $unreadResponse->assertOk();
        $unreadResponse->assertJsonPath('success', true);
        $unreadResponse->assertJsonPath('data.count', 1);
        $unreadResponse->assertJsonPath('data.by_category.contracts', 1);
        $unreadResponse->assertJsonPath('data.by_type.contract', 1);
        $unreadResponse->assertJsonPath('data.by_notification_type.contracts', 1);
    }

    public function test_notifications_can_be_filtered_by_business_context_and_opened_by_owner(): void
    {
        $context = AdminApiTestContext::create();
        $otherUser = $this->createOrganizationMember($context->organization);
        $this->allowAdminAccess(['web_admin']);

        $paymentNotification = $this->createNotification($context->organization, $context->user, [
            'type' => 'payment_workflow',
            'notification_type' => 'workflow',
            'priority' => 'high',
            'data' => [
                'title' => 'Payment approval required',
                'message' => 'Document needs approval',
                'category' => 'payments',
                'type' => 'payment_approval',
                'project_id' => '56',
            ],
        ]);
        $this->createNotification($context->organization, $context->user, [
            'type' => 'warehouse_event',
            'notification_type' => 'warehouse',
            'priority' => 'low',
            'data' => [
                'title' => 'Warehouse movement',
                'category' => 'warehouse',
                'type' => 'stock_movement',
                'project_id' => '56',
            ],
        ]);
        $this->createNotification($context->organization, $otherUser, [
            'type' => 'payment_workflow',
            'notification_type' => 'workflow',
            'priority' => 'high',
            'data' => [
                'title' => 'Foreign approval',
                'category' => 'payments',
                'type' => 'payment_approval',
                'project_id' => '56',
            ],
        ]);

        $filteredResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/notifications?category=payments&priority=high&project_id=56&type=payment_approval&per_page=5');

        $filteredResponse->assertOk();
        $filteredResponse->assertJsonPath('success', true);
        $filteredResponse->assertJsonPath('meta.total', 1);
        $filteredResponse->assertJsonPath('meta.per_page', 5);
        $filteredResponse->assertJsonPath('data.0.id', $paymentNotification->id);
        $filteredResponse->assertJsonPath('data.0.data.title', 'Payment approval required');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/notifications/{$paymentNotification->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.id', $paymentNotification->id);
        $showResponse->assertJsonPath('data.data.category', 'payments');
    }

    public function test_notification_actions_cannot_touch_another_users_notifications(): void
    {
        $context = AdminApiTestContext::create();
        $otherUser = $this->createOrganizationMember($context->organization);
        $this->allowAdminAccess(['web_admin']);

        $ownNotification = $this->createNotification($context->organization, $context->user);
        $foreignNotification = $this->createNotification($context->organization, $otherUser);

        $readResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/notifications/{$ownNotification->id}/read");

        $readResponse->assertOk();
        $readResponse->assertJsonPath('success', true);
        $this->assertNotNull($ownNotification->fresh()->read_at);

        $unreadResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/notifications/{$ownNotification->id}/unread");

        $unreadResponse->assertOk();
        $unreadResponse->assertJsonPath('success', true);
        $this->assertNull($ownNotification->fresh()->read_at);

        $foreignReadResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/notifications/{$foreignNotification->id}/read");

        $foreignReadResponse->assertNotFound();
        $foreignReadResponse->assertJsonPath('success', false);
        $this->assertNull($foreignNotification->fresh()->read_at);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/notifications/{$ownNotification->id}");

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertDatabaseMissing('notifications', ['id' => $ownNotification->id]);

        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/notifications/{$foreignNotification->id}");

        $foreignDeleteResponse->assertNotFound();
        $foreignDeleteResponse->assertJsonPath('success', false);
        $this->assertDatabaseHas('notifications', ['id' => $foreignNotification->id]);
    }

    public function test_mark_all_notifications_as_read_updates_only_authenticated_user(): void
    {
        $context = AdminApiTestContext::create();
        $otherUser = $this->createOrganizationMember($context->organization);
        $this->allowAdminAccess(['web_admin']);

        $ownFirst = $this->createNotification($context->organization, $context->user);
        $ownSecond = $this->createNotification($context->organization, $context->user);
        $foreign = $this->createNotification($context->organization, $otherUser);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/notifications/mark-all-read');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.count', 2);

        $this->assertNotNull($ownFirst->fresh()->read_at);
        $this->assertNotNull($ownSecond->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_dashboard_settings_are_saved_merged_reset_and_limited_to_user_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $this->allowAdminAccess(['web_admin', 'manager']);

        $payload = $this->dashboardPayload();

        $foreignResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/dashboard/settings?org_id={$foreignOrganization->id}", $payload);

        $foreignResponse->assertForbidden();
        $foreignResponse->assertJsonPath('success', false);
        $this->assertDatabaseMissing('user_dashboard_settings', [
            'user_id' => $context->user->id,
            'organization_id' => $foreignOrganization->id,
        ]);

        $saveResponse = $this->withHeaders($context->authHeaders())
            ->putJson('/api/v1/admin/dashboard/settings', $payload);

        $saveResponse->assertOk();
        $saveResponse->assertJsonPath('success', true);
        $saveResponse->assertJsonPath('data.user_id', $context->user->id);
        $saveResponse->assertJsonPath('data.organization_id', $context->organization->id);
        $this->assertDatabaseHas('user_dashboard_settings', [
            'user_id' => $context->user->id,
            'organization_id' => $context->organization->id,
            'layout_mode' => 'grid',
        ]);

        $getResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/dashboard/settings');

        $getResponse->assertOk();
        $getResponse->assertJsonPath('success', true);
        $getResponse->assertJsonPath('data.version', $payload['version']);
        $this->assertContains(
            'activityChart',
            collect($getResponse->json('data.items'))->pluck('id')->all()
        );

        $defaultsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/dashboard/settings/defaults');

        $defaultsResponse->assertOk();
        $defaultsResponse->assertJsonPath('success', true);
        $defaultsResponse->assertJsonPath('data.version', $payload['version']);
        $this->assertNotEmpty($defaultsResponse->json('data.items'));

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson('/api/v1/admin/dashboard/settings');

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertDatabaseMissing('user_dashboard_settings', [
            'user_id' => $context->user->id,
            'organization_id' => $context->organization->id,
        ]);
    }

    private function allowAdminAccess(array $roleSlugs): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($roleSlugs): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn($roleSlugs);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function createOrganizationMember(Organization $organization): User
    {
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        return $user;
    }

    private function createNotification(Organization $organization, User $user, array $attributes = []): Notification
    {
        return Notification::query()->create(array_replace_recursive([
            'type' => 'admin_notification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'organization_id' => $organization->id,
            'notification_type' => 'system',
            'priority' => 'normal',
            'channels' => ['in_app'],
            'delivery_status' => ['in_app' => 'delivered'],
            'data' => [
                'title' => 'Test notification',
                'category' => 'system',
                'type' => 'system',
            ],
            'metadata' => [],
            'read_at' => null,
        ], $attributes));
    }

    private function dashboardPayload(): array
    {
        return [
            'version' => (int) config('dashboard.widgets_registry.version'),
            'layout_mode' => 'grid',
            'items' => [
                [
                    'id' => 'activityChart',
                    'enabled' => true,
                    'order' => 10,
                    'size' => [
                        'xs' => 12,
                        'md' => 12,
                        'lg' => 8,
                    ],
                    'layout' => [
                        'x' => 0,
                        'y' => 0,
                        'w' => 12,
                        'h' => 4,
                    ],
                ],
            ],
        ];
    }
}
