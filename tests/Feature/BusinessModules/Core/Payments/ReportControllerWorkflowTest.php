<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ReportControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_report_applies_period_project_and_organization_scope_to_all_sections(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);

        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $counterparty = Organization::factory()->verified()->create(['name' => 'ООО Дебитор']);

        $incoming = $this->createDocument($context, $counterparty, [
            'project_id' => $project->id,
            'document_number' => 'REP-IN-001',
            'direction' => InvoiceDirection::INCOMING,
            'document_date' => '2026-05-10',
            'amount' => 1000,
            'paid_amount' => 200,
            'remaining_amount' => 800,
            'status' => PaymentDocumentStatus::APPROVED,
        ]);
        $outgoing = $this->createDocument($context, $counterparty, [
            'project_id' => $project->id,
            'document_number' => 'REP-OUT-001',
            'direction' => InvoiceDirection::OUTGOING,
            'document_date' => '2026-05-11',
            'amount' => 500,
            'paid_amount' => 0,
            'remaining_amount' => 500,
            'status' => PaymentDocumentStatus::SCHEDULED,
        ]);
        $this->createDocument($context, $counterparty, [
            'project_id' => $project->id,
            'document_number' => 'REP-OLD-001',
            'direction' => InvoiceDirection::INCOMING,
            'document_date' => '2026-04-10',
            'amount' => 700,
            'paid_amount' => 0,
            'remaining_amount' => 700,
            'status' => PaymentDocumentStatus::APPROVED,
        ]);
        $foreignDocument = $this->createDocument($foreignContext, $counterparty, [
            'project_id' => $foreignProject->id,
            'document_number' => 'REP-FOREIGN-001',
            'direction' => InvoiceDirection::INCOMING,
            'document_date' => '2026-05-10',
            'amount' => 9900,
            'paid_amount' => 0,
            'remaining_amount' => 9900,
            'status' => PaymentDocumentStatus::APPROVED,
        ]);

        $this->createTransaction($incoming, 200, '2026-05-12');
        $this->createTransaction($outgoing, 100, '2026-05-13');
        $this->createTransaction($foreignDocument, 9900, '2026-05-12');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/reports/financial?period_from=2026-05-01&period_to=2026-05-31&project_id={$project->id}&report_type=by_project");

        $response->assertOk();
        $response->assertJsonPath('data.period.from', '2026-05-01');
        $response->assertJsonPath('data.period.to', '2026-05-31');
        $response->assertJsonPath('data.meta.report_type', 'by_project');
        $response->assertJsonPath('data.meta.project_id', (string) $project->id);
        $response->assertJsonPath('data.summary.total_invoiced', '1500');
        $response->assertJsonPath('data.summary.total_paid', '200');
        $response->assertJsonPath('data.summary.total_outstanding', '1300');
        $response->assertJsonPath('data.summary.documents_count', 2);
        $response->assertJsonPath('data.summary.transactions_count', 2);
        $response->assertJsonPath('data.by_direction.incoming', '1000');
        $response->assertJsonPath('data.by_direction.outgoing', '500');
        $response->assertJsonPath('data.by_project.0.project_id', $project->id);
        $response->assertJsonPath('data.by_project.0.invoiced', '1500');
        $response->assertJsonPath('data.top_debtors.0.organization_id', $counterparty->id);
        $response->assertJsonPath('data.top_debtors.0.debt_amount', '800');
    }

    private function createDocument(
        AdminApiTestContext $context,
        Organization $counterparty,
        array $overrides = []
    ): PaymentDocument {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'counterparty_organization_id' => $counterparty->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'REP-' . uniqid(),
            'document_date' => '2026-05-10',
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 100,
            'paid_amount' => 0,
            'remaining_amount' => 100,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => '2026-05-20',
        ], $overrides));
    }

    private function createTransaction(PaymentDocument $document, float $amount, string $date): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'amount' => $amount,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => $date,
            'status' => PaymentTransactionStatus::COMPLETED,
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
                'permissions' => ['payments.reports.view'],
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
