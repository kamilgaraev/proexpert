<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource;
use App\Models\SystemAdmin;
use App\Notifications\SystemAdminTemplatePreviewNotification;
use App\Policies\SystemAdmin\NotificationTemplatePolicy;
use App\Services\Filament\NotificationTemplateManagementService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
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
        $this->assertStringNotContainsString('recipient_id', $source);
        $this->assertStringNotContainsString('send_bulk', $source);
    }

    private function templateFixture(): NotificationTemplate
    {
        return NotificationTemplate::query()->create([
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
        ]);
    }
}
