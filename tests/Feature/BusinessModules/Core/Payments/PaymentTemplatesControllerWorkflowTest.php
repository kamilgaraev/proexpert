<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentTemplatesControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_templates_are_business_readable(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/templates');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', 'advance_30');
        $response->assertJsonPath('data.0.name', 'Аванс 30%');
        $response->assertJsonPath('data.0.description', 'Авансовый платеж 30% от суммы контракта');
        $response->assertJsonPath('data.4.name', 'Произвольный аванс');
        $response->assertJsonPath('data.8.name', 'По акту выполненных работ');
        $response->assertJsonCount(9, 'data');
    }

    public function test_payment_template_calculation_is_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);

        $contract = $this->createContract($context, 120000);
        $foreignContract = $this->createContract($foreignContext, 990000);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/calculate', [
                'contract_id' => $contract->id,
                'template_id' => 'advance_30',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.contract_id', $contract->id);
        $response->assertJsonPath('data.contract_total_amount', 120000);
        $response->assertJsonPath('data.percentage', 30);
        $response->assertJsonPath('data.calculated_amount', 36000);

        $foreignResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/calculate', [
                'contract_id' => $foreignContract->id,
                'template_id' => 'advance_30',
            ]);

        $foreignResponse->assertStatus(422);
        $foreignResponse->assertJsonPath('success', false);
        $foreignResponse->assertJsonPath('message', trans_message('payments.validation_error'));
        $foreignResponse->assertJsonValidationErrors(['contract_id']);
    }

    private function createContract(AdminApiTestContext $context, float $totalAmount): Contract
    {
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Template contractor ' . uniqid(),
            'inn' => (string) random_int(1000000000, 9999999999),
        ]);

        return Contract::query()->create([
            'organization_id' => $context->organization->id,
            'contractor_id' => $contractor->id,
            'number' => 'TPL-' . uniqid(),
            'date' => '2026-05-01',
            'total_amount' => $totalAmount,
            'status' => 'active',
        ]);
    }

    private function activatePaymentsModule(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'payments'],
            [
                'name' => 'Payments',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => [
                    'payments.invoice.view',
                    'payments.invoice.create',
                ],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
