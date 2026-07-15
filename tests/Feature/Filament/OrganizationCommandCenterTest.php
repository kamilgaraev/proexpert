<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Services\Filament\OrganizationAdminActionService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OrganizationCommandCenterTest extends TestCase
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

    public function test_it_suspends_organization_and_records_audit_event(): void
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $organization = Organization::factory()->create([
            'name' => 'Command Center Org',
            'is_active' => true,
        ]);

        $event = app(OrganizationAdminActionService::class)->suspend(
            organization: $organization,
            actor: $admin,
            reason: 'manual_review',
        );

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertFalse($organization->fresh()->is_active);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'organization_id' => $organization->id,
            'actor_type' => 'system_admin',
            'actor_user_id' => null,
            'event_type' => 'system_admin.organizations.suspended',
            'action' => 'updated',
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
        ]);

        $this->assertSame($admin->id, $event->context['actor_system_admin_id']);
        $this->assertSame('manual_review', $event->context['reason']);
        $this->assertTrue($event->changes['before']['is_active']);
        $this->assertFalse($event->changes['after']['is_active']);
    }

    public function test_it_reactivates_organization_and_records_audit_event(): void
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $organization = Organization::factory()->inactive()->create([
            'name' => 'Reactivation Org',
        ]);

        $event = app(OrganizationAdminActionService::class)->reactivate(
            organization: $organization,
            actor: $admin,
        );

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertTrue($organization->fresh()->is_active);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'organization_id' => $organization->id,
            'actor_type' => 'system_admin',
            'actor_user_id' => null,
            'event_type' => 'system_admin.organizations.reactivated',
            'action' => 'updated',
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
        ]);

        $this->assertFalse($event->changes['before']['is_active']);
        $this->assertTrue($event->changes['after']['is_active']);
    }

    public function test_organization_resource_exposes_command_center_surface(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/OrganizationResource.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('ViewAction::make()', $source);
        $this->assertStringContainsString('Pages\\ViewOrganization::route', $source);
        $this->assertStringContainsString("Action::make('suspend')", $source);
        $this->assertStringContainsString("Action::make('reactivate')", $source);
        $this->assertStringContainsString('FilamentPermission::ORGANIZATIONS_SUSPEND', $source);
        $this->assertStringContainsString('FilamentPermission::ORGANIZATIONS_REACTIVATE', $source);
        $this->assertStringNotContainsString('subscription_state', $source);
        $this->assertStringContainsString("Tables\\Columns\\TextColumn::make('users_count')", $source);
        $this->assertStringContainsString('->bulkActions([])', $source);
    }
}
