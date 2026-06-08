<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\BudgetLimitAmounts;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetLimitCheckContext;
use App\BusinessModules\Features\Budgeting\Services\BudgetLimitCheckService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class BudgetLimitCheckServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('translator', $translator);
        $container->instance('config', new Repository([
            'app' => [
                'locale' => 'ru',
                'fallback_locale' => 'ru',
            ],
        ]));
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_available_limit_allows_payment_request(): void
    {
        $result = (new BudgetLimitCheckService())->check(
            $this->context(),
            new BudgetLimitAmounts(
                approvedBudgetAmount: 1_000_000.0,
                actualPaymentsAmount: 300_000.0,
                pendingApprovalAmount: 100_000.0,
                reservedAmount: 50_000.0,
                carryoverAmount: 0.0,
                adjustmentAmount: 0.0,
                exceptionAmount: 0.0,
                requestedAmount: 100_000.0,
            )
        );

        $this->assertSame(BudgetLimitCheckService::STATUS_AVAILABLE, $result->status);
        $this->assertSame(BudgetLimitCheckService::DECISION_ALLOW, $result->decision);
        $this->assertSame('Лимит доступен.', $result->message);
        $this->assertSame(550_000.0, $result->amounts->projectedAmount());
        $this->assertSame(450_000.0, $result->amounts->availableAfterRequest());
    }

    public function test_warning_status_is_returned_near_limit(): void
    {
        $result = (new BudgetLimitCheckService())->check(
            $this->context(warningThresholdRatio: 0.9),
            new BudgetLimitAmounts(
                approvedBudgetAmount: 1_000_000.0,
                actualPaymentsAmount: 700_000.0,
                pendingApprovalAmount: 100_000.0,
                reservedAmount: 50_000.0,
                carryoverAmount: 0.0,
                adjustmentAmount: 0.0,
                exceptionAmount: 0.0,
                requestedAmount: 50_000.0,
            )
        );

        $this->assertSame(BudgetLimitCheckService::STATUS_WARNING, $result->status);
        $this->assertSame(BudgetLimitCheckService::DECISION_WARN, $result->decision);
        $this->assertNull($result->requiredPermission);
        $this->assertSame(0.9, $result->amounts->usageRatio());
    }

    public function test_soft_block_requires_override_permission(): void
    {
        $result = (new BudgetLimitCheckService())->check(
            $this->context(enforcementMode: BudgetLimitCheckContext::ENFORCEMENT_SOFT_BLOCK),
            new BudgetLimitAmounts(
                approvedBudgetAmount: 1_000_000.0,
                actualPaymentsAmount: 700_000.0,
                pendingApprovalAmount: 150_000.0,
                reservedAmount: 100_000.0,
                carryoverAmount: 0.0,
                adjustmentAmount: 0.0,
                exceptionAmount: 0.0,
                requestedAmount: 100_000.0,
            )
        );

        $this->assertSame(BudgetLimitCheckService::STATUS_REQUIRES_EXCEPTION, $result->status);
        $this->assertSame(BudgetLimitCheckService::DECISION_REQUIRE_EXCEPTION, $result->decision);
        $this->assertSame(BudgetLimitCheckService::OVERRIDE_PERMISSION, $result->requiredPermission);
        $this->assertSame(50_000.0, $result->amounts->excessAmount());
    }

    public function test_hard_block_blocks_payment_without_override(): void
    {
        $result = (new BudgetLimitCheckService())->check(
            $this->context(enforcementMode: BudgetLimitCheckContext::ENFORCEMENT_HARD_BLOCK),
            new BudgetLimitAmounts(
                approvedBudgetAmount: 1_000_000.0,
                actualPaymentsAmount: 900_000.0,
                pendingApprovalAmount: 0.0,
                reservedAmount: 0.0,
                carryoverAmount: 0.0,
                adjustmentAmount: 0.0,
                exceptionAmount: 0.0,
                requestedAmount: 150_000.0,
            )
        );

        $this->assertSame(BudgetLimitCheckService::STATUS_BLOCKED, $result->status);
        $this->assertSame(BudgetLimitCheckService::DECISION_BLOCK, $result->decision);
        $this->assertNull($result->requiredPermission);
    }

    public function test_missing_approved_budget_blocks_payment_request(): void
    {
        $result = (new BudgetLimitCheckService())->check(
            $this->context(hasApprovedBudget: false),
            new BudgetLimitAmounts(
                approvedBudgetAmount: 0.0,
                actualPaymentsAmount: 0.0,
                pendingApprovalAmount: 0.0,
                reservedAmount: 0.0,
                carryoverAmount: 0.0,
                adjustmentAmount: 0.0,
                exceptionAmount: 0.0,
                requestedAmount: 50_000.0,
            )
        );

        $this->assertSame(BudgetLimitCheckService::STATUS_BLOCKED, $result->status);
        $this->assertSame(BudgetLimitCheckService::DECISION_BLOCK, $result->decision);
        $this->assertNull($result->requiredPermission);
        $this->assertSame('Платеж нельзя провести без корректировки бюджета.', $result->message);
    }

    public function test_carryovers_adjustments_and_exceptions_increase_total_limit(): void
    {
        $result = (new BudgetLimitCheckService())->check(
            $this->context(),
            new BudgetLimitAmounts(
                approvedBudgetAmount: 1_000_000.0,
                actualPaymentsAmount: 900_000.0,
                pendingApprovalAmount: 100_000.0,
                reservedAmount: 0.0,
                carryoverAmount: 100_000.0,
                adjustmentAmount: 50_000.0,
                exceptionAmount: 25_000.0,
                requestedAmount: 150_000.0,
            )
        );

        $payload = $result->toArray();

        $this->assertSame(BudgetLimitCheckService::STATUS_WARNING, $result->status);
        $this->assertSame(1_175_000.0, $payload['summary']['total_limit_amount']);
        $this->assertSame(1_150_000.0, $payload['summary']['projected_amount']);
        $this->assertSame(25_000.0, $payload['summary']['available_after_request']);
        $this->assertSame(100_000.0, $payload['sources']['carryovers']);
        $this->assertSame(50_000.0, $payload['sources']['adjustments']);
        $this->assertSame(25_000.0, $payload['sources']['exceptions']);
    }

    private function context(
        string $enforcementMode = BudgetLimitCheckContext::ENFORCEMENT_SOFT_BLOCK,
        float $warningThresholdRatio = 0.9,
        bool $hasApprovedBudget = true,
    ): BudgetLimitCheckContext {
        return new BudgetLimitCheckContext(
            operationType: 'payment_document_approval',
            operationId: 118,
            organizationId: 42,
            budgetPeriodId: 'period-uuid',
            budgetArticleId: 'article-uuid',
            responsibilityCenterId: 'cfo-uuid',
            period: '2026-01',
            currency: 'RUB',
            projectId: 1001,
            contractId: 501,
            counterpartyId: 44,
            limitId: 'limit-uuid',
            enforcementMode: $enforcementMode,
            warningThresholdRatio: $warningThresholdRatio,
            hasApprovedBudget: $hasApprovedBudget,
        );
    }
}
