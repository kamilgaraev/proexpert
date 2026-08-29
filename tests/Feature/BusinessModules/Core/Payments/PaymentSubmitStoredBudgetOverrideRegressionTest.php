<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

// Regression: ISSUE-088 — проведение повторно требовало уже сохранённое обоснование превышения бюджета
// Found by /qa on 2026-08-29
// Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PaymentSubmitStoredBudgetOverrideRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_preserves_budget_override_context_during_approval_initialization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateModule($context->organization->id, 'payments', [
            'payments.invoice.issue',
            'payments.invoice.edit',
        ]);
        $this->activateModule($context->organization->id, 'budgeting', [
            'budgeting.limits.override',
        ], true);
        $budget = $this->createBudgetLine($context->organization->id, 500.0);
        $payee = Organization::factory()->verified()->create();
        $reason = 'Срочная поставка для непрерывности работ';

        $document = PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $payee->id,
            'budget_article_id' => $budget['article']->id,
            'responsibility_center_id' => $budget['center']->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'LIMIT-SUBMIT-'.uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => PaymentDocumentStatus::DRAFT,
            'due_date' => now()->toDateString(),
            'payment_purpose' => 'Оплата материалов',
            'bank_account' => '40702810900000001002',
            'bank_bik' => '044525225',
            'created_by_user_id' => $context->user->id,
        ]);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/payments/documents/{$document->id}", [
                'budget_override_reason' => $reason,
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$document->id}/submit", [
                'budget_override_reason' => $reason,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('payment_documents', [
            'id' => $document->id,
            'budget_limit_override_reason' => $reason,
        ]);
        $this->assertDatabaseHas('budget_limit_checks', [
            'payment_document_id' => $document->id,
            'decision' => 'require_exception',
            'accepted' => true,
            'override_reason' => $reason,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function activateModule(
        int $organizationId,
        string $slug,
        array $permissions,
        bool $system = false,
    ): void {
        $module = Module::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst($slug),
                'version' => '1.0.0',
                'type' => $slug === 'payments' ? 'core' : 'feature',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => $permissions,
                'is_active' => true,
                'is_system_module' => $system,
            ]
        );
        $module->forceFill([
            'permissions' => $permissions,
            'is_system_module' => $system,
        ])->save();

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        app(ModulePermissionChecker::class)->clearModuleCache($organizationId, $slug);
    }

    /**
     * @return array{article: BudgetArticle, center: ResponsibilityCenter}
     */
    private function createBudgetLine(int $organizationId, float $planAmount): array
    {
        $period = BudgetPeriod::query()->create([
            'organization_id' => $organizationId,
            'code' => 'PER-'.uniqid(),
            'name' => 'Текущий месяц',
            'period_type' => 'month',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
        $scenario = BudgetScenario::query()->create([
            'organization_id' => $organizationId,
            'code' => 'BASE-'.uniqid(),
            'name' => 'Базовый',
            'scenario_type' => 'base',
            'is_default' => true,
            'is_active' => true,
        ]);
        $article = BudgetArticle::query()->create([
            'organization_id' => $organizationId,
            'code' => 'PAY-'.uniqid(),
            'name' => 'Платежи поставщикам',
            'budget_kind' => 'bdds',
            'flow_direction' => 'outflow',
            'is_leaf' => true,
            'is_active' => true,
        ]);
        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $organizationId,
            'center_type' => 'project',
            'code' => 'CFO-'.uniqid(),
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

        return ['article' => $article, 'center' => $center];
    }
}
