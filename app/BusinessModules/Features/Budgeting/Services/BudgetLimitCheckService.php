<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\BudgetLimitAmounts;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetLimitCheckContext;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetLimitCheckResult;

use function trans_message;

final class BudgetLimitCheckService
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_WARNING = 'warning';
    public const STATUS_EXCEEDED = 'exceeded';
    public const STATUS_REQUIRES_EXCEPTION = 'requires_exception';
    public const STATUS_BLOCKED = 'blocked';

    public const DECISION_ALLOW = 'allow';
    public const DECISION_WARN = 'warn';
    public const DECISION_REQUIRE_EXCEPTION = 'require_exception';
    public const DECISION_BLOCK = 'block';

    public const OVERRIDE_PERMISSION = 'budgeting.limits.override';

    public function check(BudgetLimitCheckContext $context, BudgetLimitAmounts $amounts): BudgetLimitCheckResult
    {
        if (!$context->hasApprovedBudget && $amounts->requestedAmount > 0.0) {
            return $this->result(
                self::STATUS_BLOCKED,
                self::DECISION_BLOCK,
                'budgeting.limits.blocked',
                $context,
                $amounts,
                null
            );
        }

        if ($amounts->excessAmount() > 0.0) {
            return $this->exceededResult($context, $amounts);
        }

        if ($amounts->usageRatio() >= $context->warningThresholdRatio) {
            return $this->result(
                self::STATUS_WARNING,
                self::DECISION_WARN,
                'budgeting.limits.warning',
                $context,
                $amounts,
                null
            );
        }

        return $this->result(
            self::STATUS_AVAILABLE,
            self::DECISION_ALLOW,
            'budgeting.limits.available',
            $context,
            $amounts,
            null
        );
    }

    private function exceededResult(BudgetLimitCheckContext $context, BudgetLimitAmounts $amounts): BudgetLimitCheckResult
    {
        if ($context->enforcementMode === BudgetLimitCheckContext::ENFORCEMENT_INFORM) {
            return $this->result(
                self::STATUS_EXCEEDED,
                self::DECISION_WARN,
                'budgeting.limits.exceeded',
                $context,
                $amounts,
                null
            );
        }

        if ($context->enforcementMode === BudgetLimitCheckContext::ENFORCEMENT_HARD_BLOCK) {
            return $this->result(
                self::STATUS_BLOCKED,
                self::DECISION_BLOCK,
                'budgeting.limits.blocked',
                $context,
                $amounts,
                null
            );
        }

        return $this->result(
            self::STATUS_REQUIRES_EXCEPTION,
            self::DECISION_REQUIRE_EXCEPTION,
            'budgeting.limits.requires_exception',
            $context,
            $amounts,
            self::OVERRIDE_PERMISSION
        );
    }

    private function result(
        string $status,
        string $decision,
        string $messageKey,
        BudgetLimitCheckContext $context,
        BudgetLimitAmounts $amounts,
        ?string $requiredPermission,
    ): BudgetLimitCheckResult {
        return new BudgetLimitCheckResult(
            status: $status,
            decision: $decision,
            message: trans_message($messageKey),
            context: $context,
            amounts: $amounts,
            requiredPermission: $requiredPermission,
        );
    }
}
