<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Activity\ActivityEvent;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Notifications\LandingResetPasswordNotification;
use App\Services\Filament\UserAdminActionService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementWorkflowTest extends TestCase
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

    public function test_it_blocks_user_through_service_and_records_audit_event(): void
    {
        $admin = $this->systemAdmin();
        $user = User::factory()->create(['is_active' => true]);

        $event = app(UserAdminActionService::class)->block($user, $admin);

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'actor_type' => 'system_admin',
            'actor_user_id' => null,
            'event_type' => 'system_admin.users.blocked',
            'action' => 'updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
        $this->assertTrue($event->changes['before']['is_active']);
        $this->assertFalse($event->changes['after']['is_active']);
    }

    public function test_it_unblocks_user_through_service_and_records_audit_event(): void
    {
        $admin = $this->systemAdmin();
        $user = User::factory()->create(['is_active' => false]);

        $event = app(UserAdminActionService::class)->unblock($user, $admin);

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'event_type' => 'system_admin.users.unblocked',
            'action' => 'updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
        $this->assertFalse($event->changes['before']['is_active']);
        $this->assertTrue($event->changes['after']['is_active']);
    }

    public function test_it_marks_email_verified_through_service_and_records_audit_event(): void
    {
        $admin = $this->systemAdmin();
        $user = User::factory()->unverified()->create();

        $event = app(UserAdminActionService::class)->markEmailVerified($user, $admin);

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'event_type' => 'system_admin.users.email_verified',
            'action' => 'updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
    }

    public function test_it_sends_password_reset_through_existing_notification_flow_and_records_audit_event(): void
    {
        Notification::fake();

        $admin = $this->systemAdmin();
        $user = User::factory()->create();

        $event = app(UserAdminActionService::class)->sendPasswordReset($user, $admin);

        $this->assertInstanceOf(ActivityEvent::class, $event);
        Notification::assertSentTo($user, LandingResetPasswordNotification::class);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'event_type' => 'system_admin.users.password_reset_sent',
            'action' => 'updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
    }

    public function test_user_resource_exposes_safe_management_surface(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/UserResource.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('ViewAction::make()', $source);
        $this->assertStringContainsString('Pages\\ViewUser::route', $source);
        $this->assertStringContainsString("Action::make('block')", $source);
        $this->assertStringContainsString("Action::make('unblock')", $source);
        $this->assertStringContainsString("Action::make('mark_email_verified')", $source);
        $this->assertStringContainsString("Action::make('send_password_reset')", $source);
        $this->assertStringContainsString('FilamentPermission::USERS_BLOCK', $source);
        $this->assertStringContainsString('FilamentPermission::USERS_VERIFY_EMAIL', $source);
        $this->assertStringContainsString('FilamentPermission::USERS_SEND_PASSWORD_RESET', $source);
        $this->assertStringNotContainsString("TextInput::make('password')", $source);
        $this->assertStringNotContainsString("TextInput::make('role", $source);
        $this->assertStringContainsString('->bulkActions([])', $source);
    }

    private function systemAdmin(): SystemAdmin
    {
        return SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
