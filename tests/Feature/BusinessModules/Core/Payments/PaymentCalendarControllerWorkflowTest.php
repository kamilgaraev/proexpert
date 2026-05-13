<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentCalendarControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_lists_current_organization_events_and_reschedules_document(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);

        $payer = Organization::factory()->verified()->create(['name' => 'ООО Плательщик']);
        $dueDocument = $this->createDocument($context, [
            'document_number' => 'CAL-DUE-001',
            'payer_organization_id' => $payer->id,
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 1500,
            'due_date' => '2026-05-10',
            'scheduled_at' => null,
            'status' => PaymentDocumentStatus::APPROVED,
        ]);
        $scheduledDocument = $this->createDocument($context, [
            'document_number' => 'CAL-SCHEDULED-001',
            'payer_organization_id' => $payer->id,
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 2500,
            'due_date' => '2026-06-01',
            'scheduled_at' => '2026-05-12 10:00:00',
            'status' => PaymentDocumentStatus::SCHEDULED,
        ]);
        $this->createDocument($foreignContext, [
            'document_number' => 'CAL-FOREIGN-001',
            'payer_organization_id' => $payer->id,
            'amount' => 9900,
            'due_date' => '2026-05-11',
            'status' => PaymentDocumentStatus::APPROVED,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/documents/calendar?start=2026-05-01&end=2026-05-31');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $dueDocument->id);
        $response->assertJsonPath('data.0.title', 'ООО Плательщик - 1500.00');
        $response->assertJsonPath('data.0.start', '2026-05-10');
        $response->assertJsonPath('data.0.backgroundColor', '#F59E0B');
        $response->assertJsonPath('data.0.extendedProps.status', 'approved');
        $response->assertJsonPath('data.1.id', $scheduledDocument->id);
        $response->assertJsonPath('data.1.start', '2026-05-12');
        $response->assertJsonPath('data.1.backgroundColor', '#3B82F6');

        $rescheduleResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$dueDocument->id}/reschedule", [
                'date' => '2026-05-20',
            ]);

        $rescheduleResponse->assertOk();
        $rescheduleResponse->assertJsonPath('data.id', $dueDocument->id);
        $rescheduleResponse->assertJsonPath('data.scheduled_at', '2026-05-20');

        $dueDocument->refresh();
        $this->assertSame('2026-05-20', $dueDocument->scheduled_at?->format('Y-m-d'));
    }

    public function test_calendar_reschedule_rejects_foreign_document(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);

        $foreignDocument = $this->createDocument($foreignContext, [
            'document_number' => 'CAL-FOREIGN-RESCHEDULE',
            'due_date' => '2026-05-10',
            'scheduled_at' => null,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$foreignDocument->id}/reschedule", [
                'date' => '2026-05-20',
            ]);

        $response->assertNotFound();
        $response->assertJsonPath('success', false);

        $foreignDocument->refresh();
        $this->assertNull($foreignDocument->scheduled_at);
    }

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'CAL-' . uniqid(),
            'document_date' => '2026-05-01',
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => '2026-05-10',
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
                'permissions' => ['payments.schedule.view', 'payments.schedule.edit'],
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
