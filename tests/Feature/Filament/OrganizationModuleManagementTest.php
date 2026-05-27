<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Activity\ActivityEvent;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\SystemAdmin;
use App\Services\Filament\ModuleAdminActionService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OrganizationModuleManagementTest extends TestCase
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

    public function test_it_manages_standalone_module_lifecycle_with_audit_events(): void
    {
        [$admin, $organization, $module] = $this->moduleFixture();

        $activation = app(ModuleAdminActionService::class)->enableForOrganization(
            organization: $organization,
            module: $module,
            actor: $admin,
            reason: 'pilot_access',
        );

        $this->assertSame('active', $activation->status);
        $this->assertFalse($activation->is_bundled_with_plan);
        $this->assertDatabaseHas('activity_events', [
            'organization_id' => $organization->id,
            'actor_type' => 'system_admin',
            'event_type' => 'system_admin.modules.enabled',
            'action' => 'updated',
            'subject_type' => OrganizationModuleActivation::class,
            'subject_id' => $activation->id,
        ]);

        $trialEvent = app(ModuleAdminActionService::class)->startTrial(
            activation: $activation,
            actor: $admin,
            days: 14,
            reason: 'trial_request',
        );

        $activation->refresh();

        $this->assertInstanceOf(ActivityEvent::class, $trialEvent);
        $this->assertSame('trial', $activation->status);
        $this->assertNotNull($activation->trial_ends_at);
        $this->assertSame('trial_request', $trialEvent->context['reason']);

        $extendEvent = app(ModuleAdminActionService::class)->extendAccess(
            activation: $activation,
            actor: $admin,
            days: 30,
            reason: 'contract_extension',
        );

        $activation->refresh();

        $this->assertInstanceOf(ActivityEvent::class, $extendEvent);
        $this->assertSame('active', $activation->status);
        $this->assertNotNull($activation->expires_at);
        $this->assertDatabaseHas('activity_events', [
            'id' => $extendEvent->id,
            'event_type' => 'system_admin.modules.access_extended',
            'subject_id' => $activation->id,
        ]);

        $disableEvent = app(ModuleAdminActionService::class)->disable(
            activation: $activation,
            actor: $admin,
            reason: 'customer_request',
        );

        $activation->refresh();

        $this->assertInstanceOf(ActivityEvent::class, $disableEvent);
        $this->assertSame('suspended', $activation->status);
        $this->assertNotNull($activation->cancelled_at);
        $this->assertFalse($activation->is_auto_renew_enabled);
        $this->assertDatabaseHas('activity_events', [
            'id' => $disableEvent->id,
            'event_type' => 'system_admin.modules.disabled',
            'subject_id' => $activation->id,
        ]);
    }

    public function test_it_records_manual_entitlement_sync_for_organization(): void
    {
        [$admin, $organization] = $this->moduleFixture();

        $event = app(ModuleAdminActionService::class)->syncEntitlements($organization, $admin);

        $this->assertInstanceOf(ActivityEvent::class, $event);
        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'organization_id' => $organization->id,
            'event_type' => 'system_admin.modules.entitlements_synced',
            'action' => 'updated',
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
        ]);
        $this->assertSame('sync_entitlements', $event->context['operation']);
    }

    public function test_module_resources_expose_safe_operations_without_direct_mutation(): void
    {
        $moduleSource = file_get_contents(app_path('Filament/Resources/ModuleResource.php'));
        $activationSource = file_get_contents(app_path('Filament/Resources/OrganizationModuleActivationResource.php'));
        $packageSource = file_get_contents(app_path('Filament/Resources/OrganizationPackageSubscriptionResource.php'));

        $this->assertIsString($moduleSource);
        $this->assertStringContainsString("Action::make('enable_for_organization')", $moduleSource);
        $this->assertStringContainsString('FilamentPermission::MODULES_MANAGE', $moduleSource);
        $this->assertStringContainsString('public static function canCreate(): bool', $moduleSource);
        $this->assertStringContainsString('return false;', $moduleSource);
        $this->assertStringContainsString('->bulkActions([])', $moduleSource);
        $this->assertStringNotContainsString('EditAction::make()', $moduleSource);

        $this->assertIsString($activationSource);
        $this->assertStringContainsString("Action::make('disable')", $activationSource);
        $this->assertStringContainsString("Action::make('enable')", $activationSource);
        $this->assertStringContainsString("Action::make('start_trial')", $activationSource);
        $this->assertStringContainsString("Action::make('extend_access')", $activationSource);
        $this->assertStringContainsString("Action::make('sync_entitlements')", $activationSource);
        $this->assertStringContainsString('FilamentPermission::MODULES_MANAGE', $activationSource);
        $this->assertStringContainsString('->bulkActions([])', $activationSource);
        $this->assertStringNotContainsString('EditAction::make()', $activationSource);

        $this->assertIsString($packageSource);
        $this->assertStringContainsString('ViewAction::make()', $packageSource);
        $this->assertStringContainsString('public static function canCreate(): bool', $packageSource);
        $this->assertStringContainsString('return false;', $packageSource);
        $this->assertStringContainsString('->bulkActions([])', $packageSource);
    }

    /**
     * @return array{0: SystemAdmin, 1: Organization, 2: Module}
     */
    private function moduleFixture(): array
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $organization = Organization::factory()->create();
        $module = Module::query()->create([
            'name' => 'Quality Control',
            'slug' => 'quality-control-' . $organization->id,
            'version' => '1.0.0',
            'type' => 'feature',
            'billing_model' => 'subscription',
            'category' => 'operations',
            'description' => 'Quality workflows',
            'pricing_config' => [
                'base_price' => 1900,
                'currency' => 'RUB',
                'duration_days' => 30,
            ],
            'features' => [],
            'permissions' => [],
            'dependencies' => [],
            'conflicts' => [],
            'limits' => [],
            'display_order' => 10,
            'is_active' => true,
            'is_system_module' => false,
            'can_deactivate' => true,
            'development_status' => 'stable',
        ]);

        return [$admin, $organization, $module];
    }
}
