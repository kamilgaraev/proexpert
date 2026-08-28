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
use App\BusinessModules\Core\Payments\Services\PaymentBudgetLimitService;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PaymentBudgetLimitLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_returns_available_balance_for_payment_document(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $document = $this->createDocument($context, $budget, 400.0);

        $check = $this->service()->check($document, $context->user);

        $this->assertSame('available', $check['status']);
        $this->assertSame('allow', $check['decision']);
        $this->assertSame('600.00', $check['summary']['available_after_request']);
    }

    public function test_payment_registration_preview_uses_selected_month_without_writes(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $document = $this->createDocument($context, $budget, 400.0);
        $document->forceFill(['scheduled_at' => now()->addMonthNoOverflow()->startOfMonth()])->save();

        $documentCheck = $this->service()->check($document->fresh(), $context->user);
        $paymentCheck = $this->service()->checkPaymentRegistration(
            $document->fresh(),
            '400.00',
            now(),
            $context->user
        );

        $this->assertSame('block', $documentCheck['decision']);
        $this->assertSame('allow', $paymentCheck['decision']);
        $this->assertSame(now()->format('Y-m'), $paymentCheck['dimensions']['period']);
        $this->assertDatabaseCount('budget_limit_checks', 0);
        $this->assertDatabaseCount('budget_limit_reservations', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_payment_budget_preview_endpoint_returns_selected_date_result_without_mutation(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $document = $this->createDocument($context, $budget, 400.0);
        $document->forceFill(['scheduled_at' => now()->addMonthNoOverflow()->startOfMonth()])->save();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/documents/{$document->id}/payment-budget-preview", [
                'amount' => '400.00',
                'transaction_date' => now()->toDateString(),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.decision', 'allow');
        $response->assertJsonPath('data.dimensions.period', now()->format('Y-m'));
        $this->assertDatabaseCount('budget_limit_checks', 0);
        $this->assertDatabaseCount('budget_limit_reservations', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_exceeded_limit_is_blocked_without_override_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 500.0);
        $document = $this->createDocument($context, $budget, 1000.0);
        $userWithoutPermission = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);

        $this->expectException(\DomainException::class);

        $this->service()->assertAllowed(
            $document,
            PaymentBudgetLimitService::OPERATION_SUBMIT,
            1000.0,
            $userWithoutPermission
        );
    }

    public function test_override_requires_permission_and_reason_and_writes_audit(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 500.0);
        $document = $this->createDocument($context, $budget, 1000.0);

        $this->expectException(\DomainException::class);

        try {
            $this->service()->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_SUBMIT,
                1000.0,
                $context->user
            );
        } finally {
            $this->service()->assertAllowed(
                $document,
                PaymentBudgetLimitService::OPERATION_SUBMIT,
                1000.0,
                $context->user,
                'Срочная поставка для непрерывности работ'
            );

            $this->assertDatabaseHas('budget_limit_checks', [
                'payment_document_id' => $document->id,
                'decision' => 'require_exception',
                'accepted' => true,
                'override_reason' => 'Срочная поставка для непрерывности работ',
            ]);
            $this->assertDatabaseHas('payment_audit_logs', [
                'payment_document_id' => $document->id,
                'action' => 'budget_limit_override',
            ]);
        }
    }

    public function test_reservation_is_adjusted_released_and_converted_after_payment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activateBudgetingModule($context->organization->id);
        $budget = $this->createBudgetLine($context->organization->id, 1000.0);
        $document = $this->createDocument($context, $budget, 400.0);

        $this->service()->assertAllowed(
            $document,
            PaymentBudgetLimitService::OPERATION_SUBMIT,
            400.0,
            $context->user
        );
        $document->forceFill(['status' => PaymentDocumentStatus::SUBMITTED])->save();
        $this->service()->syncReservation($document->fresh(), $context->user);

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
            'amount' => 400.0,
        ]);

        $document->forceFill([
            'amount' => 300.0,
            'remaining_amount' => 300.0,
        ])->save();
        $this->service()->syncReservation($document->fresh(), $context->user);

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
            'amount' => 300.0,
        ]);

        $this->service()->release($document->fresh(), 'Отменено');

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_RELEASED,
            'release_reason' => 'Отменено',
        ]);
        $reservation = BudgetLimitReservation::query()
            ->where('payment_document_id', $document->id)
            ->latest('id')
            ->firstOrFail();
        $versions = DB::table('budgeting_portfolio_liquidity_source_versions')
            ->where('source_type', 'budget_limit_reservation')
            ->where('source_id', (string) $reservation->id)
            ->orderBy('id')
            ->get();
        $this->assertGreaterThanOrEqual(3, $versions->count());
        $this->assertNull($versions->last()->payload);

        $document->forceFill(['status' => PaymentDocumentStatus::APPROVED])->save();
        $this->service()->syncReservation($document->fresh(), $context->user);
        $transaction = PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $context->organization->id,
            'project_id' => $document->project_id,
            'amount' => 300.0,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => now()->toDateString(),
            'status' => PaymentTransactionStatus::COMPLETED,
            'created_by_user_id' => $context->user->id,
        ]);
        $document->forceFill([
            'status' => PaymentDocumentStatus::PAID,
            'paid_amount' => 300.0,
            'remaining_amount' => 0.0,
        ])->save();

        $this->service()->convertAfterPayment($document->fresh(), $transaction);

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_CONVERTED,
            'amount' => 0.0,
        ]);

        $refund = PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $context->organization->id,
            'project_id' => $document->project_id,
            'amount' => -100.0,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => now()->toDateString(),
            'status' => PaymentTransactionStatus::COMPLETED,
            'reverses_transaction_id' => $transaction->id,
            'created_by_user_id' => $context->user->id,
        ]);
        $document->forceFill([
            'status' => PaymentDocumentStatus::PARTIALLY_PAID,
            'paid_amount' => 200.0,
            'remaining_amount' => 100.0,
        ])->save();

        $this->service()->reconcileAfterLedgerChange($document->fresh(), $refund);

        $this->assertDatabaseHas('budget_limit_reservations', [
            'payment_document_id' => $document->id,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
            'amount' => 100.0,
        ]);
    }

    public function test_budgeting_inactive_does_not_block_payment_lifecycle(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->createDocument($context, null, 5000.0);

        $result = $this->service()->assertAllowed(
            $document,
            PaymentBudgetLimitService::OPERATION_SUBMIT,
            5000.0,
            $context->user
        );

        $this->assertNull($result);
        $this->assertDatabaseMissing('budget_limit_checks', [
            'payment_document_id' => $document->id,
        ]);
    }

    private function service(): PaymentBudgetLimitService
    {
        return app(PaymentBudgetLimitService::class);
    }

    /**
     * @param  array{article: BudgetArticle, center: ResponsibilityCenter, line: BudgetLine}|null  $budget
     */
    private function createDocument(AdminApiTestContext $context, ?array $budget, float $amount): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'budget_article_id' => $budget['article']->id ?? null,
            'responsibility_center_id' => $budget['center']->id ?? null,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'LIMIT-'.uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => $amount,
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => PaymentDocumentStatus::DRAFT,
            'due_date' => now()->toDateString(),
            'created_by_user_id' => $context->user->id,
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
                'permissions' => [
                    'payments.invoice.edit',
                    'payments.transaction.register',
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
        app(ModulePermissionChecker::class)->clearModuleCache($organizationId, 'payments');
    }

    /**
     * @return array{article: BudgetArticle, center: ResponsibilityCenter, line: BudgetLine}
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
            'name' => 'Платежи подрядчикам',
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

        return [
            'article' => $article,
            'center' => $center,
            'line' => $line,
        ];
    }
}
