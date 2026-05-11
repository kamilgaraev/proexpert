<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Procurement\Models\ProcurementApprovalPolicy;
use App\BusinessModules\Features\Procurement\Services\ProcurementApprovalPolicyService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProcurementSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_show_and_reset_procurement_settings_without_touching_foreign_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $module = $this->createProcurementModule();
        $activation = $this->activateModule($context->organization->id, $module->id, [
            'default_currency' => 'RUB',
            'cache_ttl' => 300,
            'auto_create_invoice' => true,
        ]);
        $foreignActivation = $this->activateModule($foreignContext->organization->id, $module->id, [
            'default_currency' => 'EUR',
            'cache_ttl' => 900,
        ]);
        $foreignPolicy = ProcurementApprovalPolicy::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'budget_exceed_amount' => 777,
            'is_active' => true,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson('/api/v1/admin/procurement/settings', [
                'default_currency' => 'USD',
                'cache_ttl' => 600,
                'auto_create_invoice' => false,
                'approval_policy' => [
                    'budget_exceed_amount' => 15000,
                    'non_lowest_delta_percent' => 7.5,
                    'external_supplier_requires_identity' => false,
                    'required_approval_permission' => 'procurement.approvals.resolve',
                    'is_active' => true,
                ],
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.default_currency', 'USD');
        $updateResponse->assertJsonPath('data.cache_ttl', 600);
        $updateResponse->assertJsonPath('data.auto_create_invoice', false);
        $updateResponse->assertJsonPath('data.approval_policy.budget_exceed_amount', 15000);
        $updateResponse->assertJsonPath('data.approval_policy.non_lowest_delta_percent', 7.5);
        $updateResponse->assertJsonPath('data.approval_policy.external_supplier_requires_identity', false);

        $activation->refresh();
        $this->assertSame('USD', $activation->module_settings['default_currency']);
        $this->assertSame(600, $activation->module_settings['cache_ttl']);
        $this->assertFalse($activation->module_settings['auto_create_invoice']);
        $this->assertSame('EUR', $foreignActivation->fresh()->module_settings['default_currency']);
        $this->assertSame('777.00', (string) $foreignPolicy->fresh()->budget_exceed_amount);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/procurement/settings');

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.default_currency', 'USD');
        $showResponse->assertJsonPath('data.approval_policy.budget_exceed_amount', 15000);

        $invalidResponse = $this->withHeaders($context->authHeaders())
            ->putJson('/api/v1/admin/procurement/settings', [
                'default_currency' => 'GBP',
            ]);

        $invalidResponse->assertStatus(422);
        $this->assertSame('USD', $activation->fresh()->module_settings['default_currency']);

        $resetResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/procurement/settings/reset');

        $resetResponse->assertOk();
        $resetResponse->assertJsonPath('data.default_currency', 'RUB');
        $resetResponse->assertJsonPath('data.cache_ttl', 300);
        $resetResponse->assertJsonPath('data.approval_policy.budget_exceed_amount', 0);
        $resetResponse->assertJsonPath('data.approval_policy.external_supplier_requires_identity', true);
        $this->assertSame('EUR', $foreignActivation->fresh()->module_settings['default_currency']);
    }

    public function test_admin_without_settings_permission_cannot_update_procurement_settings(): void
    {
        $context = AdminApiTestContext::create();
        $module = $this->createProcurementModule();
        $activation = $this->activateModule($context->organization->id, $module->id, [
            'default_currency' => 'RUB',
            'cache_ttl' => 300,
        ]);
        $this->allowAdminAccessWithoutSettingsManagePermission();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->putJson('/api/v1/admin/procurement/settings', [
                'default_currency' => 'USD',
                'cache_ttl' => 900,
            ]);

        $response->assertForbidden();
        $this->assertSame('RUB', $activation->fresh()->module_settings['default_currency']);
        $this->assertSame(300, $activation->fresh()->module_settings['cache_ttl']);
    }

    private function createProcurementModule(): Module
    {
        return Module::query()->create([
            'name' => 'Procurement',
            'slug' => 'procurement',
            'version' => '1.0.0',
            'type' => 'feature',
            'billing_model' => 'free',
            'category' => 'operations',
            'is_active' => true,
            'is_system_module' => false,
            'can_deactivate' => true,
        ]);
    }

    private function activateModule(int $organizationId, int $moduleId, array $settings): OrganizationModuleActivation
    {
        return OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $moduleId,
            'status' => 'active',
            'activated_at' => now(),
            'module_settings' => $settings,
            'is_bundled_with_plan' => false,
            'is_auto_renew_enabled' => false,
        ]);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(
                    static fn (int $organizationId, string $moduleSlug): bool => in_array(
                        $moduleSlug,
                        ['procurement', 'basic-warehouse'],
                        true
                    )
                );
        });
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
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

    private function allowAdminAccessWithoutSettingsManagePermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')
                ->andReturnUsing(
                    static fn (User $user, string $permission): bool => $permission !== 'procurement.settings.manage'
                );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['custom_procurement_viewer']);
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
}
