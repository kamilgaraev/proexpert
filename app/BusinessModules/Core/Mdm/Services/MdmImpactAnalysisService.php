<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function trans_message;

class MdmImpactAnalysisService
{
    public function analyze(int $organizationId, string $entityType, ?int $entityId, array $diff = []): array
    {
        if ($entityId === null) {
            return $this->emptyImpact();
        }

        [$groups, $blockers, $warnings] = match ($entityType) {
            'contractor' => $this->contractorImpact($organizationId, $entityId, $diff),
            'supplier' => $this->supplierImpact($organizationId, $entityId, $diff),
            'budget_article' => $this->budgetArticleImpact($organizationId, $entityId, $diff),
            'responsibility_center' => $this->responsibilityCenterImpact($organizationId, $entityId, $diff),
            'project' => $this->projectImpact($organizationId, $entityId, $diff),
            'contract' => $this->contractImpact($organizationId, $entityId, $diff),
            default => [[], [], []],
        };

        $total = array_sum(array_map(static fn (array $group): int => (int) $group['count'], $groups));

        return [
            'groups' => array_values($groups),
            'total_count' => $total,
            'max_severity' => $this->maxSeverity($groups),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function contractorImpact(int $organizationId, int $entityId, array $diff): array
    {
        $contracts = $this->countByColumn('contracts', 'contractor_id', $organizationId, $entityId);
        $payments = $this->countWhereAny('payment_documents', ['payer_contractor_id', 'payee_contractor_id', 'contractor_id'], $organizationId, $entityId);
        $paidPayments = $this->countPaymentStatuses($organizationId, ['payer_contractor_id', 'payee_contractor_id', 'contractor_id'], $entityId, ['paid', 'partially_paid', 'posted']);
        $budgetLines = $this->countByColumn('budget_lines', 'counterparty_id', null, $entityId);

        $groups = [
            $this->group('contracts', $contracts, 'high'),
            $this->group('payments', $payments, $paidPayments > 0 ? 'critical' : 'medium', $paidPayments > 0),
            $this->group('budget', $budgetLines, 'medium'),
        ];

        $blockers = [];
        if ($paidPayments > 0 && $this->fieldsChanged($diff, ['name', 'inn', 'kpp', 'bank_details'])) {
            $blockers[] = [
                'code' => 'paid_payments',
                'message' => trans_message('mdm.blockers.contractor_paid_payments'),
                'count' => $paidPayments,
            ];
        }

        return [$groups, $blockers, []];
    }

    private function supplierImpact(int $organizationId, int $entityId, array $diff): array
    {
        $orders = $this->countByColumn('purchase_orders', 'supplier_id', $organizationId, $entityId);
        $requests = $this->countByColumn('supplier_requests', 'supplier_id', $organizationId, $entityId);
        $proposals = $this->countByColumn('supplier_proposals', 'supplier_id', $organizationId, $entityId);
        $contracts = $this->countByColumn('contracts', 'supplier_id', $organizationId, $entityId);

        $groups = [
            $this->group('procurement', $orders + $requests + $proposals, 'high'),
            $this->group('contracts', $contracts, 'medium'),
        ];

        $warnings = [];
        if (($orders + $requests + $proposals) > 0 && $this->fieldsChanged($diff, ['name', 'inn', 'ogrn', 'tax_number'])) {
            $warnings[] = [
                'code' => 'supplier_procurement_documents',
                'message' => trans_message('mdm.warnings.supplier_procurement_documents'),
                'count' => $orders + $requests + $proposals,
            ];
        }

        return [$groups, [], $warnings];
    }

    private function budgetArticleImpact(int $organizationId, int $entityId, array $diff): array
    {
        $budgetLines = $this->countByColumn('budget_lines', 'budget_article_id', null, $entityId);
        $payments = $this->countByColumn('payment_documents', 'budget_article_id', $organizationId, $entityId);
        $mappings = $this->countByColumn('budget_article_mappings', 'budget_article_id', $organizationId, $entityId);

        $groups = [
            $this->group('budget', $budgetLines, 'high', $budgetLines > 0 && $this->fieldsChanged($diff, ['flow_direction', 'budget_kind', 'is_leaf'])),
            $this->group('payments', $payments, 'medium'),
            $this->group('one_c', $mappings, 'medium'),
        ];

        $blockers = [];
        if ($budgetLines > 0 && $this->fieldsChanged($diff, ['flow_direction', 'budget_kind', 'is_leaf'])) {
            $blockers[] = [
                'code' => 'budget_lines_depend_on_article',
                'message' => trans_message('mdm.blockers.budget_lines_depend_on_article'),
                'count' => $budgetLines,
            ];
        }

        return [$groups, $blockers, []];
    }

    private function responsibilityCenterImpact(int $organizationId, int $entityId, array $diff): array
    {
        $budgetLines = $this->countByColumn('budget_lines', 'responsibility_center_id', null, $entityId);
        $payments = $this->countByColumn('payment_documents', 'responsibility_center_id', $organizationId, $entityId);

        $groups = [
            $this->group('budget', $budgetLines, 'high', $budgetLines > 0 && $this->fieldsChanged($diff, ['active_from', 'active_to', 'is_active'])),
            $this->group('payments', $payments, 'medium'),
        ];

        $blockers = [];
        if ($budgetLines > 0 && $this->fieldsChanged($diff, ['active_from', 'active_to', 'is_active'])) {
            $blockers[] = [
                'code' => 'budget_lines_depend_on_responsibility_center',
                'message' => trans_message('mdm.blockers.budget_lines_depend_on_responsibility_center'),
                'count' => $budgetLines,
            ];
        }

        return [$groups, $blockers, []];
    }

    private function projectImpact(int $organizationId, int $entityId, array $diff): array
    {
        $contracts = $this->countByColumn('contracts', 'project_id', $organizationId, $entityId);
        $payments = $this->countByColumn('payment_documents', 'project_id', $organizationId, $entityId);
        $budgetLines = $this->countByColumn('budget_lines', 'project_id', null, $entityId);
        $warehouses = $this->countByColumn('organization_warehouses', 'project_id', $organizationId, $entityId);

        $groups = [
            $this->group('contracts', $contracts, 'high'),
            $this->group('payments', $payments, 'medium'),
            $this->group('budget', $budgetLines, 'medium'),
            $this->group('warehouse', $warehouses, 'medium'),
        ];

        return [$groups, [], []];
    }

    private function contractImpact(int $organizationId, int $entityId, array $diff): array
    {
        $payments = $this->countByColumn('payment_documents', 'source_id', $organizationId, $entityId, static function (Builder $query): void {
            if (Schema::hasColumn('payment_documents', 'source_type')) {
                $query->whereIn('source_type', ['Contract', 'App\\Models\\Contract', 'contract']);
            }
        });
        $acts = $this->countByColumn('contract_performance_acts', 'contract_id', $organizationId, $entityId);
        $budgetLines = $this->countByColumn('budget_lines', 'contract_id', null, $entityId);

        $groups = [
            $this->group('payments', $payments, 'medium'),
            $this->group('contracts', $acts, 'high'),
            $this->group('budget', $budgetLines, 'medium'),
        ];

        return [$groups, [], []];
    }

    private function emptyImpact(): array
    {
        return [
            'groups' => [],
            'total_count' => 0,
            'max_severity' => 'info',
            'blockers' => [],
            'warnings' => [],
        ];
    }

    private function countByColumn(
        string $table,
        string $column,
        ?int $organizationId,
        int $entityId,
        ?callable $callback = null
    ): int {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $query = DB::table($table)->where($column, $entityId);
        $this->applyOrganizationScope($query, $table, $organizationId);
        $this->applySoftDeleteScope($query, $table);

        if ($callback !== null) {
            $callback($query);
        }

        return (int) $query->count();
    }

    private function countWhereAny(string $table, array $columns, int $organizationId, int $entityId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $availableColumns = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn($table, $column)));
        if ($availableColumns === []) {
            return 0;
        }

        $query = DB::table($table)->where(function (Builder $nested) use ($availableColumns, $entityId): void {
            foreach ($availableColumns as $index => $column) {
                $index === 0
                    ? $nested->where($column, $entityId)
                    : $nested->orWhere($column, $entityId);
            }
        });
        $this->applyOrganizationScope($query, $table, $organizationId);
        $this->applySoftDeleteScope($query, $table);

        return (int) $query->count();
    }

    private function countPaymentStatuses(int $organizationId, array $columns, int $entityId, array $statuses): int
    {
        if (! Schema::hasTable('payment_documents') || ! Schema::hasColumn('payment_documents', 'status')) {
            return 0;
        }

        $availableColumns = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn('payment_documents', $column)));
        if ($availableColumns === []) {
            return 0;
        }

        $query = DB::table('payment_documents')
            ->whereIn('status', $statuses)
            ->where(function (Builder $nested) use ($availableColumns, $entityId): void {
                foreach ($availableColumns as $index => $column) {
                    $index === 0
                        ? $nested->where($column, $entityId)
                        : $nested->orWhere($column, $entityId);
                }
            });
        $this->applyOrganizationScope($query, 'payment_documents', $organizationId);
        $this->applySoftDeleteScope($query, 'payment_documents');

        return (int) $query->count();
    }

    private function applyOrganizationScope(Builder $query, string $table, ?int $organizationId): void
    {
        if ($organizationId !== null && Schema::hasColumn($table, 'organization_id')) {
            $query->where('organization_id', $organizationId);
        }
    }

    private function applySoftDeleteScope(Builder $query, string $table): void
    {
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
    }

    private function group(string $key, int $count, string $severity, bool $blocker = false): array
    {
        return [
            'key' => $key,
            'label' => trans_message("mdm.impact.{$key}"),
            'count' => $count,
            'severity' => $severity,
            'blocker' => $blocker,
        ];
    }

    private function fieldsChanged(array $diff, array $fields): bool
    {
        foreach ($diff as $item) {
            if (isset($item['field']) && in_array($item['field'], $fields, true)) {
                return true;
            }
        }

        return false;
    }

    private function maxSeverity(array $groups): string
    {
        $order = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $max = 'info';

        foreach ($groups as $group) {
            $severity = (string) ($group['severity'] ?? 'info');
            if (($order[$severity] ?? 0) > ($order[$max] ?? 0)) {
                $max = $severity;
            }
        }

        return $max;
    }
}
