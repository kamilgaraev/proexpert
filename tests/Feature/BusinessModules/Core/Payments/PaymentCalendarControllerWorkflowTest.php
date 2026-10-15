<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

class PaymentCalendarControllerWorkflowTest extends TestCase
{
    use EnablesImmutableAuditWriter;
    use RefreshDatabase;

    public function test_calendar_serializes_empty_undated_currency_totals_as_object(): void
    {
        $this->travelTo('2026-05-01 09:00:00');

        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context, [
            'document_number' => 'CAL-DATED-ONLY',
            'direction' => InvoiceDirection::OUTGOING,
            'due_date' => '2026-05-20',
            'status' => PaymentDocumentStatus::SUBMITTED,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/documents/calendar?start=2026-05-01&end=2026-05-31');

        $response->assertOk();
        $response->assertJsonPath('data.items.0.document_id', $document->id);
        $response->assertJsonPath('data.undated.items_count', 0);
        $response->assertJsonPath('data.undated.items', []);
        $content = $response->getContent();
        $this->assertIsString($content);
        $payload = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $payload->data->undated->totals_by_currency);
        $this->assertSame([], get_object_vars($payload->data->undated->totals_by_currency));
    }

    public function test_calendar_lists_current_organization_events_and_reschedules_document(): void
    {
        $this->travelTo('2026-05-01 09:00:00');
        $this->enableImmutableAuditWriter();

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
        $response->assertJsonCount(2, 'data.items');
        $response->assertJsonCount(2, 'data.events');
        $response->assertJsonPath('data.items.0.document_id', $dueDocument->id);
        $response->assertJsonPath('data.items.0.title', 'Поступление: 1 500 RUB - CAL-DUE-001');
        $response->assertJsonPath('data.items.0.date', '2026-05-10');
        $response->assertJsonPath('data.items.0.bucket_label', 'Утверждено');
        $response->assertJsonPath('data.events.0.extendedProps.status', 'approved');
        $response->assertJsonPath('data.items.1.document_id', $scheduledDocument->id);
        $response->assertJsonPath('data.items.1.date', '2026-05-12');
        $response->assertJsonPath('data.items.1.bucket_label', 'По графику');
        $response->assertJsonPath('data.summary.items_count', 2);
        $response->assertJsonPath('data.cash_gap.available', false);

        $rescheduleResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$dueDocument->id}/reschedule", [
                'date' => '2026-05-20',
                'reason' => 'Перенос по согласованному графику оплат',
            ]);

        $this->assertSame(200, $rescheduleResponse->getStatusCode(), (string) $rescheduleResponse->getContent());
        $rescheduleResponse->assertJsonPath('data.id', $dueDocument->id);
        $rescheduleResponse->assertJsonPath('data.scheduled_at', '2026-05-20');

        $dueDocument->refresh();
        $this->assertSame('2026-05-20', $dueDocument->scheduled_at?->format('Y-m-d'));
        $this->assertDatabaseHas('payment_audit_logs', [
            'payment_document_id' => $dueDocument->id,
            'action' => 'rescheduled',
        ]);
    }

    public function test_calendar_returns_cash_gap_forecast_when_opening_balance_is_provided(): void
    {
        $this->travelTo('2026-05-01 09:00:00');

        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);

        $this->createDocument($context, [
            'document_number' => 'CAL-GAP-IN-001',
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 1500,
            'due_date' => '2026-05-10',
            'status' => PaymentDocumentStatus::APPROVED,
        ]);
        $this->createDocument($context, [
            'document_number' => 'CAL-GAP-OUT-001',
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 2500,
            'due_date' => '2026-05-12',
            'status' => PaymentDocumentStatus::SCHEDULED,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson(
                '/api/v1/admin/payments/documents/calendar?start=2026-05-01&end=2026-05-31'
                . '&currency=RUB&opening_balance=1000&cash_gap_scenario=base'
            );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.cash_gap.available', true);
        $response->assertJsonPath('data.cash_gap.currency', 'RUB');
        $response->assertJsonPath('data.cash_gap.opening_balance', 1000);
        $response->assertJsonPath('data.cash_gap.scenario', 'base');
        $response->assertJsonPath('data.cash_gap.forecast.cash_gap.has_gap', true);
        $response->assertJsonPath('data.cash_gap.forecast.cash_gap.first_gap_date', '2026-05-12');
        $response->assertJsonPath('data.cash_gap.forecast.cash_gap.max_gap_amount', '150.00');
        $response->assertJsonPath('data.cash_gap.forecast.closing_balance', '-150.00');
    }

    public function test_calendar_rejects_too_large_period(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/documents/calendar?start=2026-01-01&end=2026-12-31');

        $response->assertUnprocessable();
        $response->assertJsonPath('success', false);
    }

    public function test_calendar_reschedule_requires_business_reason(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createDocument($context, [
            'document_number' => 'CAL-REASON-001',
            'due_date' => '2026-05-10',
            'scheduled_at' => null,
            'status' => PaymentDocumentStatus::APPROVED,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$document->id}/reschedule", [
                'date' => '2026-05-20',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('success', false);

        $document->refresh();
        $this->assertNull($document->scheduled_at);
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
                'reason' => 'Проверка недоступности платежа другой организации',
            ]);

        $response->assertNotFound();
        $response->assertJsonPath('success', false);

        $foreignDocument->refresh();
        $this->assertNull($foreignDocument->scheduled_at);
    }

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        $attributes = array_merge([
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
        ], $overrides);

        if (!array_key_exists('remaining_amount', $overrides)) {
            $attributes['remaining_amount'] = $attributes['amount'];
        }

        return PaymentDocument::query()->create($attributes);
    }

    private function activateBudgetingModule(int $organizationId): void
    {
        $manifest = json_decode((string) file_get_contents(config_path('ModuleList/features/budgeting.json')), true, 512, JSON_THROW_ON_ERROR);
        $module = Module::query()->firstOrCreate(['slug' => $manifest['slug']], [
            'name' => $manifest['name'],
            'version' => $manifest['version'],
            'type' => $manifest['type'],
            'billing_model' => $manifest['billing_model'],
            'category' => $manifest['category'],
            'permissions' => $manifest['permissions'],
            'is_active' => true,
            'is_system_module' => true,
        ]);
        $module->forceFill(['is_system_module' => true])->save();
        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        app(ModulePermissionChecker::class)->clearModuleCache($organizationId, 'budgeting');
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
