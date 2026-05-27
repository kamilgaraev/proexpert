<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use App\BusinessModules\Features\Notifications\Models\Notification as DomainNotification;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\Filament\Resources\NotificationTemplateResource;
use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Notifications\SystemAdminTemplatePreviewNotification;
use App\Policies\SystemAdmin\NotificationTemplatePolicy;
use App\Services\Filament\NotificationTemplateManagementService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SystemAdminRoleService::class)->clearCache();
    }

    protected function tearDown(): void
    {
        Auth::guard('system_admin')->logout();
        app(SystemAdminRoleService::class)->clearCache();

        parent::tearDown();
    }

    public function test_template_preview_renders_subject_and_content_with_sample_data(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
            'name' => 'Editor Admin',
            'email' => 'editor-admin@example.test',
        ]);
        $template = $this->templateFixture();

        $preview = app(NotificationTemplateManagementService::class)->preview($template, $admin);

        $this->assertSame('Hello Editor Admin', $preview['subject']);
        $this->assertStringContainsString('Editor Admin', $preview['content']);
        $this->assertStringContainsString(config('app.name'), $preview['content']);
        $this->assertSame('email', $preview['channel']);
    }

    public function test_send_test_notifies_only_current_system_admin(): void
    {
        Notification::fake();

        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
            'name' => 'Current Admin',
            'email' => 'current-admin@example.test',
        ]);
        $otherAdmin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
            'email' => 'other-admin@example.test',
        ]);
        $template = $this->templateFixture();

        app(NotificationTemplateManagementService::class)->sendTest($template, $admin);

        Notification::assertSentTo(
            $admin,
            SystemAdminTemplatePreviewNotification::class,
            fn (SystemAdminTemplatePreviewNotification $notification): bool => $notification->subject
                === 'Hello Current Admin'
                && str_contains($notification->content, 'Current Admin'),
        );
        Notification::assertNotSentTo($otherAdmin, SystemAdminTemplatePreviewNotification::class);
    }

    public function test_notification_template_actions_are_authorized_and_safe(): void
    {
        $contentManager = SystemAdmin::factory()->role('content_manager')->create(['is_active' => true]);
        $qaEngineer = SystemAdmin::factory()->role('qa_engineer')->create(['is_active' => true]);
        $template = $this->templateFixture();
        $policy = app(NotificationTemplatePolicy::class);
        $source = (string) file_get_contents(app_path('Filament/Resources/NotificationTemplateResource.php'));

        $this->actingAs($contentManager, 'system_admin');

        $this->assertTrue(NotificationTemplateResource::canViewAny());
        $this->assertTrue($policy->sendTest($contentManager, $template));
        $this->assertFalse($policy->sendTest($qaEngineer, $template));
        $this->assertStringContainsString("Action::make('preview')", $source);
        $this->assertStringContainsString("Action::make('send_test')", $source);
        $this->assertStringContainsString("Action::make('send_to_audience')", $source);
        $this->assertStringContainsString("Forms\\Components\\Select::make('audience')", $source);
        $this->assertStringContainsString("Forms\\Components\\Select::make('recipient_user_ids')", $source);
        $this->assertStringContainsString('->multiple()', $source);
        $this->assertStringContainsString('requiresConfirmation()', $source);
    }

    public function test_template_can_be_sent_to_selected_active_users(): void
    {
        Queue::fake();

        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
            'name' => 'Current Admin',
            'email' => 'current-admin@example.test',
        ]);
        $targetUser = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target-user@example.test',
            'is_active' => true,
        ]);
        $otherUser = User::factory()->create([
            'name' => 'Other User',
            'email' => 'other-user@example.test',
            'is_active' => true,
        ]);
        $inactiveUser = User::factory()->create([
            'name' => 'Inactive User',
            'email' => 'inactive-user@example.test',
            'is_active' => false,
        ]);
        $template = $this->templateFixture([
            'channel' => 'in_app',
            'subject' => 'Notice {{user.name}}',
            'content' => 'Hello {{user.name}} from {{system_admin.name}}.',
        ]);

        $result = app(NotificationTemplateManagementService::class)->sendToUsers(
            $template,
            $admin,
            [$targetUser->id, $inactiveUser->id],
        );

        $this->assertSame(1, $result['sent_count']);
        $this->assertSame([$targetUser->id], $result['recipient_ids']);

        $sentNotification = DomainNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $targetUser->id)
            ->firstOrFail();

        $this->assertSame('system.test', $sentNotification->type);
        $this->assertSame('system_admin_broadcast', $sentNotification->notification_type);
        $this->assertSame(['in_app'], $sentNotification->channels);
        $this->assertSame('Notice Target User', $sentNotification->data['title']);
        $this->assertSame('Hello Target User from Current Admin.', $sentNotification->data['message']);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $otherUser->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $inactiveUser->id,
        ]);
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_template_can_be_sent_to_all_active_users(): void
    {
        Queue::fake();

        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
            'name' => 'Current Admin',
            'email' => 'current-admin@example.test',
        ]);
        $activeUsers = User::factory()
            ->count(3)
            ->sequence(
                ['name' => 'First User', 'email' => 'first-user@example.test'],
                ['name' => 'Second User', 'email' => 'second-user@example.test'],
                ['name' => 'Third User', 'email' => 'third-user@example.test'],
            )
            ->create(['is_active' => true]);
        $inactiveUser = User::factory()->create([
            'name' => 'Inactive User',
            'email' => 'inactive-broadcast@example.test',
            'is_active' => false,
        ]);
        $template = $this->templateFixture([
            'channel' => 'in_app',
            'subject' => 'Platform update',
            'content' => 'Important update for {{user.name}}.',
        ]);

        $result = app(NotificationTemplateManagementService::class)->sendToAllUsers($template, $admin);

        $this->assertSame(3, $result['sent_count']);
        $this->assertArrayNotHasKey('recipient_ids', $result);

        foreach ($activeUsers as $user) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'notification_type' => 'system_admin_broadcast',
            ]);
        }

        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $inactiveUser->id,
        ]);
        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'system_admin.notifications.broadcast_sent',
            'actor_type' => 'system_admin',
            'subject_type' => NotificationTemplate::class,
            'subject_id' => $template->id,
        ]);

        $auditEvent = ActivityEvent::query()
            ->where('event_type', 'system_admin.notifications.broadcast_sent')
            ->where('subject_id', $template->id)
            ->firstOrFail();
        $after = $auditEvent->changes['after'] ?? [];

        $this->assertSame(3, $after['sent_count']);
        $this->assertSame($activeUsers->pluck('id')->all(), $after['recipient_sample_ids']);
        $this->assertSame(3, $after['recipient_sample_count']);
        $this->assertSame(0, $after['omitted_recipient_count']);
        $this->assertArrayNotHasKey('recipient_ids', $after);
        $this->assertNotEmpty($after['recipient_ids_hash']);
        Queue::assertPushed(SendNotificationJob::class, 3);
    }

    public function test_organization_template_broadcast_is_limited_to_active_organization_members(): void
    {
        Queue::fake();

        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
            'name' => 'Current Admin',
            'email' => 'org-admin@example.test',
        ]);
        $targetOrganization = Organization::factory()->create(['name' => 'Target Org']);
        $otherOrganization = Organization::factory()->create(['name' => 'Other Org']);
        $targetUser = User::factory()->create([
            'name' => 'Target Member',
            'email' => 'target-member@example.test',
            'is_active' => true,
            'current_organization_id' => $targetOrganization->id,
        ]);
        $otherOrganizationUser = User::factory()->create([
            'name' => 'Other Member',
            'email' => 'other-member@example.test',
            'is_active' => true,
            'current_organization_id' => $otherOrganization->id,
        ]);
        $inactiveMembershipUser = User::factory()->create([
            'name' => 'Inactive Member',
            'email' => 'inactive-member@example.test',
            'is_active' => true,
            'current_organization_id' => $targetOrganization->id,
        ]);
        $noOrganizationUser = User::factory()->create([
            'name' => 'No Organization',
            'email' => 'no-organization@example.test',
            'is_active' => true,
        ]);
        $targetUser->organizations()->attach($targetOrganization->id, ['is_active' => true]);
        $otherOrganizationUser->organizations()->attach($otherOrganization->id, ['is_active' => true]);
        $inactiveMembershipUser->organizations()->attach($targetOrganization->id, ['is_active' => false]);
        $template = $this->templateFixture([
            'organization_id' => $targetOrganization->id,
            'channel' => 'in_app',
            'subject' => 'Organization update',
            'content' => 'Update for {{organization.name}} and {{user.name}}.',
        ]);

        $result = app(NotificationTemplateManagementService::class)->sendToAllUsers($template, $admin);

        $this->assertSame(1, $result['sent_count']);
        $this->assertSame([$targetUser->id], $result['recipient_sample_ids']);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $targetUser->id,
            'organization_id' => $targetOrganization->id,
            'notification_type' => 'system_admin_broadcast',
        ]);
        foreach ([$otherOrganizationUser, $inactiveMembershipUser, $noOrganizationUser] as $user) {
            $this->assertDatabaseMissing('notifications', [
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
            ]);
        }

        $auditEvent = ActivityEvent::query()
            ->where('event_type', 'system_admin.notifications.broadcast_sent')
            ->where('subject_id', $template->id)
            ->firstOrFail();
        $after = $auditEvent->changes['after'] ?? [];

        $this->assertSame($targetOrganization->id, $auditEvent->organization_id);
        $this->assertSame(1, $after['sent_count']);
        $this->assertSame([$targetUser->id], $after['recipient_sample_ids']);
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_organization_template_selected_recipients_ignore_users_outside_organization(): void
    {
        Queue::fake();

        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
            'name' => 'Current Admin',
            'email' => 'selected-org-admin@example.test',
        ]);
        $targetOrganization = Organization::factory()->create(['name' => 'Target Org']);
        $otherOrganization = Organization::factory()->create(['name' => 'Other Org']);
        $targetUser = User::factory()->create([
            'name' => 'Selected Member',
            'email' => 'selected-member@example.test',
            'is_active' => true,
            'current_organization_id' => $targetOrganization->id,
        ]);
        $otherOrganizationUser = User::factory()->create([
            'name' => 'Selected Outside',
            'email' => 'selected-outside@example.test',
            'is_active' => true,
            'current_organization_id' => $otherOrganization->id,
        ]);
        $targetUser->organizations()->attach($targetOrganization->id, ['is_active' => true]);
        $otherOrganizationUser->organizations()->attach($otherOrganization->id, ['is_active' => true]);
        $template = $this->templateFixture([
            'organization_id' => $targetOrganization->id,
            'channel' => 'in_app',
            'subject' => 'Selected update',
            'content' => 'Selected update for {{organization.name}}.',
        ]);

        $result = app(NotificationTemplateManagementService::class)->sendToUsers(
            $template,
            $admin,
            [$targetUser->id, $otherOrganizationUser->id],
        );

        $this->assertSame(1, $result['sent_count']);
        $this->assertSame([$targetUser->id], $result['recipient_ids']);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $targetUser->id,
            'organization_id' => $targetOrganization->id,
            'notification_type' => 'system_admin_broadcast',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $otherOrganizationUser->id,
        ]);
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    private function templateFixture(array $overrides = []): NotificationTemplate
    {
        return NotificationTemplate::query()->create(array_merge([
            'type' => 'system.test',
            'channel' => 'email',
            'name' => 'System test template',
            'subject' => 'Hello {{system_admin.name}}',
            'content' => 'Message for {{system_admin.name}} from {{system.app_name}}.',
            'variables' => [
                'system_admin.name' => 'System admin name',
                'system.app_name' => 'Application name',
            ],
            'locale' => 'ru',
            'is_default' => true,
            'is_active' => true,
            'version' => 1,
        ], $overrides));
    }
}
