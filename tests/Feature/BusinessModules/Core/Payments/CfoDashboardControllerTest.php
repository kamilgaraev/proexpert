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
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\AdvanceAccountTransaction;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CfoDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cfo_dashboard_returns_scoped_finance_command_center_contract(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);

        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $context->organization->id,
            'center_type' => 'project',
            'code' => 'CFO-' . uniqid(),
            'name' => 'ЦФО проекта',
            'is_active' => true,
        ]);

        $incoming = $this->createDocument($context, [
            'project_id' => $project->id,
            'responsibility_center_id' => $center->id,
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 1000,
            'paid_amount' => 200,
            'remaining_amount' => 800,
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
        $outgoing = $this->createDocument($context, [
            'project_id' => $project->id,
            'responsibility_center_id' => $center->id,
            'direction' => InvoiceDirection::OUTGOING,
            'status' => PaymentDocumentStatus::PENDING_APPROVAL,
            'amount' => 400,
            'paid_amount' => 100,
            'remaining_amount' => 300,
            'due_date' => now()->addDay()->toDateString(),
            'budget_limit_status' => 'requires_exception',
            'budget_limit_decision' => 'require_exception',
            'budget_limit_message' => 'Превышен лимит бюджета',
            'budget_limit_checked_at' => now(),
        ]);
        $foreignDocument = $this->createDocument($foreignContext, [
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 9900,
            'paid_amount' => 0,
            'remaining_amount' => 9900,
        ]);

        $this->createTransaction($incoming, 200);
        $this->createTransaction($outgoing, 50);
        $this->createTransaction($foreignDocument, 9900);
        $this->createAdvanceTransaction($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/cfo-dashboard?' . http_build_query([
                'period_start' => now()->subDay()->toDateString(),
                'period_end' => now()->addDays(7)->toDateString(),
                'responsibility_center_id' => $center->uuid,
                'currency' => 'RUB',
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.summary.payable', 300);
        $response->assertJsonPath('data.summary.receivable', 800);
        $response->assertJsonPath('data.summary.net_position', 500);
        $response->assertJsonPath('data.summary.active_documents_count', 2);
        $response->assertJsonPath('data.summary.pending_approval_count', 1);
        $response->assertJsonPath('data.summary.limit_overrun_count', 1);
        $response->assertJsonPath('data.upcoming_payments.0.id', $outgoing->id);
        $response->assertJsonPath('data.expected_receipts.0.id', $incoming->id);
        $response->assertJsonPath('data.limit_overruns.0.id', $outgoing->id);
        $response->assertJsonPath('data.approval_blockers.0.type', 'payment_document');
        $response->assertJsonCount(0, 'data.one_c_issues');
        $response->assertJsonPath('data.by_projects.0.project_id', $project->id);
        $response->assertJsonPath('data.by_responsibility_centers.0.responsibility_center_id', $center->uuid);
        $response->assertJsonPath('meta.filters.organization_id', $context->organization->id);
        $response->assertJsonPath('meta.filters.responsibility_center_id', $center->uuid);

        $unfilteredResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/cfo-dashboard?' . http_build_query([
                'period_start' => now()->subDay()->toDateString(),
                'period_end' => now()->addDays(7)->toDateString(),
                'currency' => 'RUB',
            ]));

        $unfilteredResponse->assertOk();
        $unfilteredResponse->assertJsonPath('data.one_c_issues.0.has_external_code', false);
    }

    public function test_cfo_dashboard_rejects_foreign_company_filter(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/cfo-dashboard?company_id=' . $foreignContext->organization->id);

        $response->assertStatus(422);
    }

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'CFO-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => now()->addDays(3)->toDateString(),
        ], $overrides));
    }

    private function createTransaction(PaymentDocument $document, float $amount): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'amount' => $amount,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => now()->toDateString(),
            'status' => PaymentTransactionStatus::COMPLETED,
        ]);
    }

    private function createAdvanceTransaction(AdminApiTestContext $context, Project $project): AdvanceAccountTransaction
    {
        $user = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);

        return AdvanceAccountTransaction::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'type' => AdvanceAccountTransaction::TYPE_ISSUE,
            'amount' => 120,
            'description' => 'Командировочные расходы',
            'document_number' => 'ADV-' . uniqid(),
            'document_date' => now()->toDateString(),
            'balance_after' => 120,
            'reporting_status' => AdvanceAccountTransaction::STATUS_REPORTED,
            'created_by_user_id' => $context->user->id,
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
                'permissions' => ['payments.dashboard.view'],
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
