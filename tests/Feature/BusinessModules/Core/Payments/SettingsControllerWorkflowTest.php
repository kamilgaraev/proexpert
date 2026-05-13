<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class SettingsControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_update_ignores_empty_optional_values_and_persists_false_flags(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $activation = $this->activatePaymentsModule($context->organization->id);

        $response = $this->withHeaders($context->authHeaders())
            ->putJson('/api/v1/admin/payments/settings', [
                'default_payment_terms_days' => '',
                'overdue_notification_days_before' => '',
                'default_currency' => '',
                'enable_auto_overdue' => false,
                'allow_partial_payments' => false,
                'default_vat_rate' => 0,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.default_payment_terms_days', 30);
        $response->assertJsonPath('data.overdue_notification_days_before', 3);
        $response->assertJsonPath('data.default_currency', 'RUB');
        $response->assertJsonPath('data.enable_auto_overdue', false);
        $response->assertJsonPath('data.allow_partial_payments', false);
        $response->assertJsonPath('data.default_vat_rate', 0);

        $activation->refresh();
        $settings = $activation->module_settings['payment_settings'];
        $this->assertFalse($settings['enable_auto_overdue']);
        $this->assertFalse($settings['allow_partial_payments']);
        $this->assertSame(0, $settings['default_vat_rate']);
    }

    public function test_settings_are_isolated_by_current_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $foreignActivation = $this->activatePaymentsModule($foreignContext->organization->id, [
            'payment_settings' => [
                'default_payment_terms_days' => 45,
                'default_currency' => 'USD',
            ],
        ]);

        $this->withHeaders($context->authHeaders())
            ->putJson('/api/v1/admin/payments/settings', [
                'default_payment_terms_days' => 10,
                'default_currency' => 'EUR',
            ])
            ->assertOk();

        $ownResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/settings');
        $foreignActivation->refresh();

        $ownResponse->assertOk();
        $ownResponse->assertJsonPath('data.default_payment_terms_days', 10);
        $ownResponse->assertJsonPath('data.default_currency', 'EUR');
        $this->assertSame(45, $foreignActivation->module_settings['payment_settings']['default_payment_terms_days']);
        $this->assertSame('USD', $foreignActivation->module_settings['payment_settings']['default_currency']);
    }

    private function activatePaymentsModule(int $organizationId, array $moduleSettings = []): OrganizationModuleActivation
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'payments'],
            [
                'name' => 'Payments',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => ['payments.settings.view', 'payments.settings.manage'],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        return OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
            'module_settings' => $moduleSettings,
        ]);
    }
}
