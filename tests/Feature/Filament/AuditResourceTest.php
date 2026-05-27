<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ActivityEventResource;
use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuditResourceTest extends TestCase
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

    public function test_audit_resource_is_visible_only_to_audit_roles_and_read_only(): void
    {
        $event = ActivityEvent::query()->create([
            'module' => 'system_admin',
            'event_type' => 'system_admin.users.blocked',
            'action' => 'updated',
            'severity' => 'warning',
            'title' => 'User blocked',
            'occurred_at' => now(),
        ]);

        $this->actingAsRole('security_auditor');

        $this->assertTrue(ActivityEventResource::canViewAny());
        $this->assertTrue(ActivityEventResource::canView($event));
        $this->assertFalse(ActivityEventResource::canCreate());
        $this->assertFalse(ActivityEventResource::canEdit($event));
        $this->assertFalse(ActivityEventResource::canDelete($event));
        $this->assertFalse(ActivityEventResource::canDeleteAny());

        $this->actingAsRole('content_manager');

        $this->assertFalse(ActivityEventResource::canViewAny());
        $this->assertFalse(ActivityEventResource::canView($event));

        Auth::guard('system_admin')->logout();
        $this->actingAs(User::factory()->create());

        $this->assertFalse(ActivityEventResource::canViewAny());
    }

    public function test_audit_resource_masks_sensitive_detail_values(): void
    {
        $organization = Organization::factory()->create();
        $event = ActivityEvent::query()->create([
            'organization_id' => $organization->id,
            'actor_type' => 'system_admin',
            'actor_name' => 'Security Admin',
            'actor_email' => 'security@example.test',
            'interface' => 'admin',
            'module' => 'system_admin',
            'event_type' => 'system_admin.billing.updated',
            'action' => 'updated',
            'severity' => 'critical',
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
            'subject_label' => $organization->name,
            'title' => 'Billing updated',
            'changes' => [
                'before' => [
                    'status' => 'trial',
                    'password' => 'secret',
                    'card_number' => '4111111111111111',
                ],
                'after' => [
                    'status' => 'active',
                    'api_token' => str_repeat('a', 64),
                ],
            ],
            'context' => [
                'request_id' => 'visible-request',
                'authorization' => 'Bearer ' . str_repeat('b', 64),
            ],
            'occurred_at' => now(),
        ]);

        $changes = ActivityEventResource::redactedDetails($event->changes);
        $context = ActivityEventResource::redactedDetails($event->context);

        $this->assertSame('trial', $changes['before.status']);
        $this->assertSame('active', $changes['after.status']);
        $this->assertNotSame('secret', $changes['before.password']);
        $this->assertNotSame('4111111111111111', $changes['before.card_number']);
        $this->assertNotSame(str_repeat('a', 64), $changes['after.api_token']);
        $this->assertSame('visible-request', $context['request_id']);
        $this->assertNotSame('Bearer ' . str_repeat('b', 64), $context['authorization']);
    }

    public function test_audit_resource_declares_required_filters(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/ActivityEventResource.php'));

        $this->assertStringContainsString("SelectFilter::make('actor_type')", $source);
        $this->assertStringContainsString("SelectFilter::make('organization_id')", $source);
        $this->assertStringContainsString("SelectFilter::make('action')", $source);
        $this->assertStringContainsString("SelectFilter::make('severity')", $source);
        $this->assertStringContainsString("SelectFilter::make('subject_type')", $source);
        $this->assertStringContainsString("Filter::make('occurred_at')", $source);
    }

    private function actingAsRole(string $role): SystemAdmin
    {
        $admin = SystemAdmin::factory()->role($role)->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        return $admin;
    }
}
