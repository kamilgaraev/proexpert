<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentApprovalControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_accepts_admin_notes_contract_and_persists_decision_comment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createPendingApprovalDocument($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/approvals/documents/{$document->id}/approve", [
                'notes' => 'Проверено бухгалтерией',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.approval_status.is_fully_approved', true);

        $this->assertDatabaseHas('payment_approvals', [
            'payment_document_id' => $document->id,
            'approver_user_id' => $context->user->id,
            'status' => 'approved',
            'decision_comment' => 'Проверено бухгалтерией',
        ]);
    }

    public function test_reject_returns_approval_status_payload_and_persists_reason(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createPendingApprovalDocument($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/approvals/documents/{$document->id}/reject", [
                'reason' => 'Нет подтверждающих документов',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.approval_status.is_rejected', true);

        $this->assertDatabaseHas('payment_approvals', [
            'payment_document_id' => $document->id,
            'approver_user_id' => $context->user->id,
            'status' => 'rejected',
            'decision_comment' => 'Нет подтверждающих документов',
        ]);
    }

    public function test_my_approvals_returns_admin_queue_payload_with_pagination_and_tenant_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $document = $this->createPendingApprovalDocument($context);

        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($foreignContext->organization->id);
        $foreignDocument = $this->createPendingApprovalDocument($foreignContext);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/approvals/my?status=pending&per_page=15&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 15);
        $response->assertJsonPath('meta.total', 1);
        $this->assertEquals(1000.0, $response->json('summary.total_amount'));

        $ids = collect($response->json('data'))->pluck('payment_document_id')->all();
        $this->assertContains($document->id, $ids);
        $this->assertNotContains($foreignDocument->id, $ids);

        $approval = $response->json('data.0');
        $this->assertIsInt($approval['id']);
        $this->assertSame('pending', $approval['status']);
        $this->assertArrayHasKey('due_date', $approval);
        $this->assertSame($document->document_number, $approval['payment_document']['document_number']);
    }

    public function test_final_approval_is_blocked_when_budget_limit_requires_exception_without_reason(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $document = $this->createPendingApprovalDocument($context);
        $budget = $this->createBudgetLine($context->organization->id, 500.0);
        $document->forceFill([
            'budget_article_id' => $budget['article']->id,
            'responsibility_center_id' => $budget['center']->id,
        ])->save();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/approvals/documents/{$document->id}/approve", [
                'notes' => 'Проверено',
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('payment_documents', [
            'id' => $document->id,
            'status' => PaymentDocumentStatus::PENDING_APPROVAL->value,
        ]);
    }

    public function test_final_approval_allows_budget_limit_exception_with_override_reason(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $document = $this->createPendingApprovalDocument($context);
        $budget = $this->createBudgetLine($context->organization->id, 500.0);
        $document->forceFill([
            'budget_article_id' => $budget['article']->id,
            'responsibility_center_id' => $budget['center']->id,
        ])->save();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/approvals/documents/{$document->id}/approve", [
                'notes' => 'Проверено',
                'budget_override_reason' => 'Срочная оплата по договору',
            ]);

        $response->assertOk();

        $document->refresh();
        $this->assertSame(PaymentDocumentStatus::APPROVED, $document->status);
        $this->assertSame('requires_exception', $document->budget_limit_status);
        $this->assertDatabaseHas('budget_limit_checks', [
            'payment_document_id' => $document->id,
            'status' => 'requires_exception',
            'decision' => 'require_exception',
            'accepted' => true,
            'override_reason' => 'Срочная оплата по договору',
        ]);
        $this->assertDatabaseHas('payment_audit_logs', [
            'payment_document_id' => $document->id,
            'action' => 'budget_limit_override',
        ]);
    }

    private function createPendingApprovalDocument(AdminApiTestContext $context): PaymentDocument
    {
        $document = PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'APP-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => PaymentDocumentStatus::PENDING_APPROVAL,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        PaymentApproval::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $context->organization->id,
            'approval_role' => 'chief_accountant',
            'approver_user_id' => $context->user->id,
            'approval_level' => 1,
            'approval_order' => 1,
            'status' => 'pending',
        ]);

        return $document;
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
                    'payments.transaction.approve',
                    'payments.transaction.reject',
                    'payments.transaction.view',
                    'budgeting.limits.override',
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

    private function activateBudgetingModule(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'budgeting'],
            [
                'name' => 'Budgeting',
                'version' => '1.0.0',
                'type' => 'feature',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => ['budgeting.limits.override'],
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

    /**
     * @return array{article: BudgetArticle, center: ResponsibilityCenter, line: BudgetLine}
     */
    private function createBudgetLine(int $organizationId, float $planAmount): array
    {
        $period = BudgetPeriod::query()->create([
            'organization_id' => $organizationId,
            'code' => 'PER-' . uniqid(),
            'name' => 'Текущий месяц',
            'period_type' => 'month',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);

        $scenario = BudgetScenario::query()->create([
            'organization_id' => $organizationId,
            'code' => 'BASE-' . uniqid(),
            'name' => 'Базовый',
            'scenario_type' => 'base',
            'is_default' => true,
            'is_active' => true,
        ]);

        $article = BudgetArticle::query()->create([
            'organization_id' => $organizationId,
            'code' => 'PAY-' . uniqid(),
            'name' => 'Платежи подрядчикам',
            'budget_kind' => 'bdds',
            'flow_direction' => 'outflow',
            'is_leaf' => true,
            'is_active' => true,
        ]);

        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $organizationId,
            'center_type' => 'project',
            'code' => 'CFO-' . uniqid(),
            'name' => 'ЦФО проекта',
            'is_active' => true,
        ]);

        $version = BudgetVersion::query()->create([
            'organization_id' => $organizationId,
            'budget_period_id' => $period->id,
            'scenario_id' => $scenario->id,
            'budget_kind' => 'bdds',
            'version_number' => 1,
            'name' => 'Активный бюджет',
            'status' => 'active',
            'approved_at' => now(),
            'activated_at' => now(),
        ]);

        $line = BudgetLine::query()->create([
            'budget_version_id' => $version->id,
            'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id,
            'currency' => 'RUB',
        ]);

        BudgetAmount::query()->create([
            'budget_line_id' => $line->id,
            'month' => now()->startOfMonth()->toDateString(),
            'plan_amount' => $planAmount,
            'forecast_amount' => $planAmount,
            'currency' => 'RUB',
        ]);

        return [
            'article' => $article,
            'center' => $center,
            'line' => $line,
        ];
    }
}
