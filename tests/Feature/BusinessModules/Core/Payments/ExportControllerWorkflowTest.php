<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ExportControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_export_ignores_empty_optional_filters(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->createDocument($context, ['document_number' => 'EXPORT-001']);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/export/excel', [
                'filters' => [
                    'status' => '',
                    'document_type' => '',
                    'date_from' => '',
                    'date_to' => '',
                    'contractor_id' => '',
                    'project_id' => '',
                ],
            ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('content-type')
        );
    }

    public function test_onec_export_rejects_foreign_document_ids(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);
        $foreignDocument = $this->createDocument($foreignContext);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/export/1c', [
                'document_ids' => [$foreignDocument->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['document_ids.0']);
    }

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'EXP-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $overrides));
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
                'permissions' => ['payments.reports.export'],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        OrganizationModuleActivation::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'module_id' => $module->id,
            ],
            [
                'status' => 'active',
                'activated_at' => now(),
            ]
        );
    }
}
