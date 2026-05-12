<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentScheduleControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_schedule_templates_are_business_readable(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/schedules/templates');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', 'equal_2');
        $response->assertJsonPath('data.0.name', 'Равными платежами (2 платежа)');
        $response->assertJsonPath('data.0.description', 'График из 2 равных платежей каждые 30 дней');
        $response->assertJsonPath('data.3.name', 'Аванс 30%');
        $response->assertJsonPath('data.6.name', 'Ежемесячно равными платежами');
    }

    public function test_payment_schedule_creation_replaces_manual_installments_and_feeds_overdue_registry(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context, 1500);
        PaymentSchedule::query()->create([
            'payment_document_id' => $document->id,
            'installment_number' => 1,
            'due_date' => now()->subDays(10)->toDateString(),
            'amount' => 500,
            'status' => 'pending',
            'notes' => 'Old schedule',
        ]);

        $mismatchResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/schedules', [
                'payment_document_id' => $document->id,
                'installments' => [
                    [
                        'installment_number' => 1,
                        'due_date' => now()->subDays(5)->toDateString(),
                        'amount' => 1400,
                    ],
                ],
            ]);

        $mismatchResponse->assertStatus(422);
        $this->assertSame(1, PaymentSchedule::query()->where('payment_document_id', $document->id)->count());

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/schedules', [
                'payment_document_id' => $document->id,
                'installments' => [
                    [
                        'installment_number' => 1,
                        'due_date' => now()->subDays(5)->toDateString(),
                        'amount' => 700,
                        'notes' => 'Авансовый платеж',
                    ],
                    [
                        'installment_number' => 2,
                        'due_date' => now()->addDays(10)->toDateString(),
                        'amount' => 800,
                        'notes' => 'Финальный платеж',
                    ],
                ],
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonCount(2, 'data');
        $this->assertSame(2, PaymentSchedule::query()->where('payment_document_id', $document->id)->count());

        $documentScheduleResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/schedules?payment_document_id={$document->id}");

        $documentScheduleResponse->assertOk();
        $documentScheduleResponse->assertJsonPath('data.summary.total', 2);
        $documentScheduleResponse->assertJsonPath('data.summary.total_amount', 1500);

        $overdueResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/schedules/overdue');

        $overdueResponse->assertOk();
        $overdueResponse->assertJsonPath('meta.total_count', 1);
        $overdueResponse->assertJsonPath('meta.total_amount', 700);

        PaymentSchedule::query()
            ->where('payment_document_id', $document->id)
            ->where('installment_number', 1)
            ->update(['status' => 'paid']);

        $lockedUpdateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/payments/schedules', [
                'payment_document_id' => $document->id,
                'installments' => [
                    [
                        'installment_number' => 1,
                        'due_date' => now()->addDays(15)->toDateString(),
                        'amount' => 1500,
                    ],
                ],
            ]);

        $lockedUpdateResponse->assertStatus(422);
        $this->assertSame(2, PaymentSchedule::query()->where('payment_document_id', $document->id)->count());
    }

    private function createDocument(AdminApiTestContext $context, int $amount): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'payer_organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'INV-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => $amount,
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'payment_purpose' => 'Оплата работ',
            'due_date' => now()->addDays(20)->toDateString(),
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
                    'payments.schedule.view',
                    'payments.schedule.create',
                    'payments.schedule.edit',
                    'payments.schedule.delete',
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
