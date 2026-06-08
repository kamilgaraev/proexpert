<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class BudgetLimitCheckResult
{
    public function __construct(
        public string $status,
        public string $decision,
        public string $message,
        public BudgetLimitCheckContext $context,
        public BudgetLimitAmounts $amounts,
        public ?string $requiredPermission,
    ) {
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'decision' => $this->decision,
            'message' => $this->message,
            'required_permission' => $this->requiredPermission,
            'dimensions' => $this->context->dimensions(),
            'operation' => $this->context->operation(),
            'limit' => [
                'id' => $this->context->limitId,
                'currency' => $this->context->currency,
                'enforcement_mode' => $this->context->enforcementMode,
                'warning_threshold_ratio' => $this->context->warningThresholdRatio,
                'has_approved_budget' => $this->context->hasApprovedBudget,
            ],
            'sources' => [
                'approved_budget' => $this->amounts->money($this->amounts->approvedBudgetAmount),
                'actual_payments' => $this->amounts->money($this->amounts->actualPaymentsAmount),
                'pending_approvals' => $this->amounts->money($this->amounts->pendingApprovalAmount),
                'reserves' => $this->amounts->money($this->amounts->reservedAmount),
                'carryovers' => $this->amounts->money($this->amounts->carryoverAmount),
                'adjustments' => $this->amounts->money($this->amounts->adjustmentAmount),
                'exceptions' => $this->amounts->money($this->amounts->exceptionAmount),
            ],
            'summary' => $this->amounts->toArray(),
            'audit_trail' => [
                'event_type' => 'budget_limit_checked',
                'requires_reason' => $this->requiredPermission !== null,
                'override_permission' => $this->requiredPermission,
            ],
        ];
    }
}
