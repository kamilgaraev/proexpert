<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Services\Filament\SystemAdminAuditService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SystemAdminAuditServiceTest extends TestCase
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

    public function test_it_records_system_admin_action_without_organization(): void
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $event = app(SystemAdminAuditService::class)->record(
            actor: $admin,
            eventType: 'system_admin.profile.updated',
            action: 'updated',
            subjectType: 'system_admin',
            subjectId: $admin->id,
            subjectLabel: $admin->email,
            title: 'Профиль администратора обновлен',
            description: 'Системный администратор обновил свой профиль.',
            context: [
                'request_id' => 'test-request-id',
            ],
        );

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'organization_id' => null,
            'actor_type' => 'system_admin',
            'actor_user_id' => null,
            'interface' => 'admin',
            'module' => 'system_admin',
            'event_type' => 'system_admin.profile.updated',
            'subject_type' => 'system_admin',
            'subject_id' => $admin->id,
        ]);

        $this->assertSame($admin->id, $event->context['actor_system_admin_id']);
        $this->assertSame('super_admin', $event->context['actor_system_admin_role']);
        $this->assertSame('test-request-id', $event->context['request_id']);
    }

    public function test_it_records_deleted_model_from_current_system_admin(): void
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $organization = Organization::factory()->create([
            'name' => 'Audit Target',
        ]);

        $this->actingAs($admin, 'system_admin');

        $event = app(SystemAdminAuditService::class)->recordDeletedModel(
            record: $organization,
            resourceClass: 'App\\Filament\\Resources\\OrganizationResource',
        );

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'organization_id' => $organization->id,
            'actor_type' => 'system_admin',
            'actor_user_id' => null,
            'event_type' => 'system_admin.filament.deleted',
            'action' => 'deleted',
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
        ]);

        $this->assertSame('App\\Filament\\Resources\\OrganizationResource', $event->context['resource_class']);
        $this->assertSame($admin->id, $event->context['actor_system_admin_id']);
    }

    public function test_it_does_not_record_model_action_without_authenticated_system_admin(): void
    {
        $organization = Organization::factory()->create();

        $event = app(SystemAdminAuditService::class)->recordDeletedModel(
            record: $organization,
            resourceClass: 'App\\Filament\\Resources\\OrganizationResource',
        );

        $this->assertNull($event);
        $this->assertDatabaseCount('activity_events', 0);
    }
}

