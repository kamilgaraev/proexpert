<?php

declare(strict_types=1);

namespace App\Services\ErpControls;

use function trans_message;

final class ErpControlRegistry
{
    public const DOMAINS = [
        'finance',
        'payments',
        'procurement',
        'warehouse',
        'budgeting',
        'mdm',
        'one_c_exchange',
    ];

    public const RISK_LEVELS = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    public function operation(string $code): ?array
    {
        return $this->operationDefinitions()[$code] ?? null;
    }

    public function operations(array $filters = []): array
    {
        $operations = array_values($this->operationDefinitions());

        if (($filters['domain'] ?? null) !== null) {
            $operations = array_values(array_filter(
                $operations,
                static fn (array $operation): bool => $operation['domain'] === $filters['domain']
            ));
        }

        if (($filters['risk_level'] ?? null) !== null) {
            $operations = array_values(array_filter(
                $operations,
                static fn (array $operation): bool => $operation['risk_level'] === $filters['risk_level']
            ));
        }

        if (($filters['operation'] ?? null) !== null) {
            $needle = mb_strtolower((string) $filters['operation']);
            $operations = array_values(array_filter(
                $operations,
                static fn (array $operation): bool => str_contains(mb_strtolower($operation['code']), $needle)
                    || str_contains(mb_strtolower($operation['business_label']), $needle)
            ));
        }

        return $operations;
    }

    public function policies(array $filters = []): array
    {
        $policies = $this->policyDefinitions();

        if (($filters['domain'] ?? null) !== null) {
            $policies = array_values(array_filter(
                $policies,
                static fn (array $policy): bool => $policy['domain'] === $filters['domain']
            ));
        }

        if (($filters['risk_level'] ?? null) !== null) {
            $policies = array_values(array_filter(
                $policies,
                static fn (array $policy): bool => $policy['risk_level'] === $filters['risk_level']
            ));
        }

        if (($filters['operation'] ?? null) !== null) {
            $operation = (string) $filters['operation'];
            $policies = array_values(array_filter(
                $policies,
                static fn (array $policy): bool => $policy['source_operation'] === $operation
                    || $policy['target_operation'] === $operation
                    || str_contains($policy['code'], $operation)
            ));
        }

        return $policies;
    }

    public function summary(array $policies): array
    {
        $summary = [
            'total' => count($policies),
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        foreach ($policies as $policy) {
            $riskLevel = (string) ($policy['risk_level'] ?? 'low');
            if (array_key_exists($riskLevel, $summary)) {
                $summary[$riskLevel]++;
            }
        }

        return $summary;
    }

    private function operationDefinitions(): array
    {
        return [
            'payments.invoice.create' => $this->controlOperation(
                'payments.invoice.create',
                'payments',
                'high',
                trans_message('erp_controls.operations.payments_invoice_create'),
                ['payments.invoice.create'],
                [],
                ['organization_id', 'document_id']
            ),
            'payments.transaction.approve' => $this->controlOperation(
                'payments.transaction.approve',
                'payments',
                'critical',
                trans_message('erp_controls.operations.payments_transaction_approve'),
                ['payments.transaction.approve'],
                ['payments.invoice.create', 'payments.counterparty_account.manage'],
                ['organization_id', 'document_id']
            ),
            'procurement.purchase_requests.approve' => $this->controlOperation(
                'procurement.purchase_requests.approve',
                'procurement',
                'high',
                trans_message('erp_controls.operations.procurement_purchase_requests_approve'),
                ['procurement.purchase_requests.approve'],
                ['procurement.purchase_requests.create'],
                ['organization_id', 'project_id', 'document_id']
            ),
            'procurement.purchase_orders.receive' => $this->controlOperation(
                'procurement.purchase_orders.receive',
                'procurement',
                'high',
                trans_message('erp_controls.operations.procurement_purchase_orders_receive'),
                ['procurement.purchase_orders.mark_delivery'],
                ['procurement.purchase_orders.confirm'],
                ['organization_id', 'project_id', 'document_id']
            ),
            'warehouse.inventory.approve' => $this->controlOperation(
                'warehouse.inventory.approve',
                'warehouse',
                'high',
                trans_message('erp_controls.operations.warehouse_inventory_approve'),
                ['warehouse.inventory'],
                ['warehouse.receipts'],
                ['organization_id', 'project_id', 'document_id']
            ),
            'budgeting.budgets.approve' => $this->controlOperation(
                'budgeting.budgets.approve',
                'budgeting',
                'critical',
                trans_message('erp_controls.operations.budgeting_budgets_approve'),
                ['budgeting.budgets.approve'],
                ['budgeting.budgets.create', 'budgeting.budgets.edit'],
                ['organization_id', 'period_id', 'document_id']
            ),
            'budgeting.budgets.activate' => $this->controlOperation(
                'budgeting.budgets.activate',
                'budgeting',
                'critical',
                trans_message('erp_controls.operations.budgeting_budgets_activate'),
                ['budgeting.budgets.activate'],
                ['budgeting.budgets.approve'],
                ['organization_id', 'period_id', 'document_id']
            ),
            'budgeting.periods.close' => $this->controlOperation(
                'budgeting.periods.close',
                'budgeting',
                'critical',
                trans_message('erp_controls.operations.budgeting_periods_close'),
                ['budgeting.periods.close'],
                ['budgeting.periods.reopen'],
                ['organization_id', 'period_id']
            ),
            'mdm.change_requests.create' => $this->controlOperation(
                'mdm.change_requests.create',
                'mdm',
                'high',
                trans_message('erp_controls.operations.mdm_change_requests_create'),
                ['mdm.change_requests.create'],
                [],
                ['organization_id', 'mdm_record_id']
            ),
            'mdm.change_requests.apply' => $this->controlOperation(
                'mdm.change_requests.apply',
                'mdm',
                'critical',
                trans_message('erp_controls.operations.mdm_change_requests_apply'),
                ['mdm.change_requests.apply'],
                ['mdm.change_requests.create', 'mdm.change_requests.approve'],
                ['organization_id', 'mdm_record_id']
            ),
            'one_c_exchange.conflicts.resolve' => $this->controlOperation(
                'one_c_exchange.conflicts.resolve',
                'one_c_exchange',
                'critical',
                trans_message('erp_controls.operations.one_c_exchange_conflicts_resolve'),
                ['one_c_exchange.conflicts.resolve'],
                ['one_c_exchange.manage_tokens', 'one_c_exchange.manage_mappings'],
                ['organization_id', 'one_c_conflict_id']
            ),
        ];
    }

    private function policyDefinitions(): array
    {
        return [
            $this->policy(
                'payment_create_and_approve',
                'payments',
                'critical',
                'payments.invoice.create',
                'payments.transaction.approve',
                trans_message('erp_controls.policies.payment_create_and_approve')
            ),
            $this->policy(
                'payment_counterparty_change_and_pay',
                'payments',
                'critical',
                'payments.counterparty_account.manage',
                'payments.transaction.approve',
                trans_message('erp_controls.policies.payment_counterparty_change_and_pay')
            ),
            $this->policy(
                'procurement_create_select_receive',
                'procurement',
                'high',
                'procurement.purchase_requests.approve',
                'procurement.purchase_orders.receive',
                trans_message('erp_controls.policies.procurement_create_select_receive')
            ),
            $this->policy(
                'warehouse_receipt_and_inventory_approve',
                'warehouse',
                'high',
                'warehouse.receipts',
                'warehouse.inventory.approve',
                trans_message('erp_controls.policies.warehouse_receipt_and_inventory_approve')
            ),
            $this->policy(
                'budget_submit_approve_activate',
                'budgeting',
                'critical',
                'budgeting.budgets.approve',
                'budgeting.budgets.activate',
                trans_message('erp_controls.policies.budget_submit_approve_activate')
            ),
            $this->policy(
                'mdm_submit_and_apply',
                'mdm',
                'critical',
                'mdm.change_requests.create',
                'mdm.change_requests.apply',
                trans_message('erp_controls.policies.mdm_submit_and_apply')
            ),
            $this->policy(
                'one_c_token_and_conflict_resolve',
                'one_c_exchange',
                'high',
                'one_c_exchange.manage_tokens',
                'one_c_exchange.conflicts.resolve',
                trans_message('erp_controls.policies.one_c_token_and_conflict_resolve')
            ),
        ];
    }

    private function controlOperation(
        string $code,
        string $domain,
        string $riskLevel,
        string $label,
        array $requiredPermissions,
        array $prohibitedSameActorOperations,
        array $scopeKeys
    ): array {
        return [
            'code' => $code,
            'domain' => $domain,
            'risk_level' => $riskLevel,
            'business_label' => $label,
            'required_permissions' => $requiredPermissions,
            'prohibited_same_actor_operations' => $prohibitedSameActorOperations,
            'requires_dual_control' => $prohibitedSameActorOperations !== [],
            'requires_reason' => false,
            'requires_audit' => true,
            'scope_keys' => $scopeKeys,
        ];
    }

    private function policy(
        string $code,
        string $domain,
        string $riskLevel,
        string $sourceOperation,
        string $targetOperation,
        string $label
    ): array {
        return [
            'code' => $code,
            'domain' => $domain,
            'risk_level' => $riskLevel,
            'source_operation' => $sourceOperation,
            'target_operation' => $targetOperation,
            'same_actor_forbidden' => true,
            'same_role_forbidden' => $riskLevel === 'critical',
            'scope_match' => ['organization_id', 'document_id'],
            'override_permission' => 'erp_controls.override',
            'override_requires_second_approver' => $riskLevel === 'critical',
            'override_available' => false,
            'label' => $label,
        ];
    }
}
