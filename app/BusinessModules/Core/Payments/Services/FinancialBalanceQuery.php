<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\DTOs\FinancialBalance;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FinancialBalanceQuery
{
    private const DOCUMENT_STATUSES = [
        PaymentDocumentStatus::SUBMITTED->value,
        PaymentDocumentStatus::PENDING_APPROVAL->value,
        PaymentDocumentStatus::APPROVED->value,
        PaymentDocumentStatus::SCHEDULED->value,
        PaymentDocumentStatus::PARTIALLY_PAID->value,
        PaymentDocumentStatus::PAID->value,
    ];

    private const TRANSACTION_STATUSES = [
        PaymentTransactionStatus::COMPLETED->value,
        PaymentTransactionStatus::REFUNDED->value,
    ];

    public function forOrganization(int $organizationId, ?string $direction = null, ?int $projectId = null): FinancialBalance
    {
        $documents = $this->documents($organizationId, null, $direction, $projectId);
        $transactions = $this->transactions($organizationId, null, $direction, $projectId);

        return FinancialBalance::fromLedger(
            (string) ((clone $documents)->sum(DB::raw($this->invoicedAmountSql())) ?? 0),
            (string) ((clone $transactions)->where('payment_transactions.amount', '>', 0)
                ->sum('payment_transactions.amount') ?? 0),
            (string) BigDecimal::of((string) ((clone $transactions)
                ->where('payment_transactions.amount', '<', 0)
                ->sum('payment_transactions.amount') ?? 0))->abs(),
            '0',
            (string) ((clone $transactions)->where('payment_documents.invoice_type', 'advance')
                ->sum('payment_transactions.amount') ?? 0),
        );
    }

    public function forContract(Contract $contract): FinancialBalance
    {
        return $this->forContracts((int) $contract->organization_id, [(int) $contract->id])[$contract->id];
    }

    public function forAct(ContractPerformanceAct $act): FinancialBalance
    {
        $organizationId = (int) $act->contract->organization_id;
        $currency = $act->currency ?: 'RUB';
        $transactions = $this->transactions($organizationId)
            ->where('payment_documents.invoiceable_type', ContractPerformanceAct::class)
            ->where('payment_documents.invoiceable_id', $act->id)
            ->where('payment_documents.currency', $currency)
            ->where('payment_transactions.organization_id', $organizationId)
            ->where('payment_transactions.currency', $currency);

        return FinancialBalance::fromLedger(
            $act->status === ContractPerformanceAct::STATUS_ANNULLED ? '0' : (string) $act->amount,
            (string) (clone $transactions)->where('payment_transactions.amount', '>', 0)->sum('payment_transactions.amount'),
            (string) BigDecimal::of((string) (clone $transactions)
                ->where('payment_transactions.amount', '<', 0)->sum('payment_transactions.amount'))->abs(),
        );
    }

    public function forContracts(int $organizationId, array $contractIds): array
    {
        $contractIds = array_values(array_unique(array_map('intval', $contractIds)));
        if ($contractIds === []) {
            return [];
        }

        $balances = [];
        foreach ($contractIds as $contractId) {
            $balances[$contractId] = [
                'invoiced' => '0',
                'paid' => '0',
                'refunded' => '0',
                'advance' => '0',
            ];
        }

        $documents = $this->documents($organizationId, $contractIds)
            ->selectRaw($this->contractIdentitySql().' AS resolved_contract_id')
            ->selectRaw('SUM('.$this->invoicedAmountSql().') AS invoiced_amount')
            ->groupByRaw($this->contractIdentitySql())
            ->get();
        foreach ($documents as $row) {
            $balances[(int) $row->resolved_contract_id]['invoiced'] = (string) $row->invoiced_amount;
        }

        $transactions = $this->transactions($organizationId, $contractIds)
            ->selectRaw($this->contractIdentitySql().' AS resolved_contract_id')
            ->selectRaw(
                'SUM(CASE WHEN payment_transactions.amount > 0 THEN payment_transactions.amount ELSE 0 END) AS paid_amount'
            )
            ->selectRaw(
                'ABS(SUM(CASE WHEN payment_transactions.amount < 0 THEN payment_transactions.amount ELSE 0 END)) AS refunded_amount'
            )
            ->selectRaw(
                "SUM(CASE WHEN payment_documents.invoice_type = 'advance' THEN payment_transactions.amount ELSE 0 END) AS advance_amount"
            )
            ->groupByRaw($this->contractIdentitySql())
            ->get();
        foreach ($transactions as $row) {
            $contractId = (int) $row->resolved_contract_id;
            $balances[$contractId]['paid'] = (string) $row->paid_amount;
            $balances[$contractId]['refunded'] = (string) $row->refunded_amount;
            $balances[$contractId]['advance'] = (string) $row->advance_amount;
        }

        return array_map(
            static fn (array $row): FinancialBalance => FinancialBalance::fromLedger(
                $row['invoiced'],
                $row['paid'],
                $row['refunded'],
                '0',
                $row['advance'],
            ),
            $balances,
        );
    }

    private function documents(
        int $organizationId,
        ?array $contractIds = null,
        ?string $direction = null,
        ?int $projectId = null
    ): Builder {
        return DB::table('payment_documents')
            ->leftJoin('contract_performance_acts', function ($join): void {
                $join->on('contract_performance_acts.id', '=', 'payment_documents.invoiceable_id')
                    ->where('payment_documents.invoiceable_type', ContractPerformanceAct::class);
            })
            ->where('payment_documents.organization_id', $organizationId)
            ->whereNull('payment_documents.deleted_at')
            ->when($projectId !== null, static fn (Builder $query): Builder => $query->where('payment_documents.project_id', $projectId))
            ->whereIn('payment_documents.status', self::DOCUMENT_STATUSES)
            ->when($direction !== null, static fn (Builder $query): Builder => $query
                ->where('payment_documents.direction', $direction))
            ->when($contractIds !== null, function (Builder $query) use ($contractIds): void {
                $query->where(function (Builder $scope) use ($contractIds): void {
                    $scope->where(function (Builder $direct) use ($contractIds): void {
                        $direct->where('payment_documents.invoiceable_type', Contract::class)
                            ->whereIn('payment_documents.invoiceable_id', $contractIds);
                    })->orWhereIn('contract_performance_acts.contract_id', $contractIds);
                });
            });
    }

    private function transactions(
        int $organizationId,
        ?array $contractIds = null,
        ?string $direction = null,
        ?int $projectId = null
    ): Builder {
        return $this->documents($organizationId, $contractIds, $direction, $projectId)
            ->join(
                'payment_transactions',
                'payment_transactions.payment_document_id',
                '=',
                'payment_documents.id'
            )
            ->whereIn('payment_transactions.status', self::TRANSACTION_STATUSES);
    }

    private function invoicedAmountSql(): string
    {
        $documentClass = str_replace("'", "''", PaymentDocument::class);
        $requestType = PaymentDocumentType::PAYMENT_REQUEST->value;
        $orderType = PaymentDocumentType::PAYMENT_ORDER->value;
        $statuses = implode(', ', array_map(static fn (string $status): string => "'{$status}'", self::DOCUMENT_STATUSES));
        $dimensions = implode(' AND ', array_map(
            static fn (string $field): string => "linked_order.{$field} IS NOT DISTINCT FROM payment_documents.{$field}",
            ['project_id', 'payer_organization_id', 'payer_contractor_id', 'payee_organization_id', 'payee_contractor_id',
                'contractor_id', 'counterparty_organization_id'],
        ));
        $actClass = str_replace("'", "''", ContractPerformanceAct::class);
        $requestContract = $this->contractIdentitySql();
        $orderContract = $this->contractIdentitySql('linked_order', 'linked_order_act');

        return "CASE WHEN payment_documents.document_type = '{$requestType}' AND payment_documents.amount > 0 THEN "
            .'GREATEST(payment_documents.amount - (SELECT COALESCE(SUM(GREATEST(linked_order.amount, 0)), 0) '
            .'FROM payment_documents AS linked_order '
            .'LEFT JOIN contract_performance_acts AS linked_order_act ON linked_order_act.id = linked_order.invoiceable_id '
            ."AND linked_order.invoiceable_type = '{$actClass}' "
            .'WHERE linked_order.organization_id = payment_documents.organization_id '
            .'AND linked_order.deleted_at IS NULL '
            ."AND linked_order.document_type = '{$orderType}' AND linked_order.source_type = '{$documentClass}' "
            .'AND linked_order.source_id = payment_documents.id '
            ."AND linked_order.status IN ({$statuses}) "
            .'AND linked_order.currency = payment_documents.currency '
            ."AND COALESCE(linked_order.direction, 'outgoing') = COALESCE(payment_documents.direction, 'outgoing') "
            ."AND {$dimensions} "
            ."AND (({$requestContract}) IS NOT NULL AND ({$requestContract}) = ({$orderContract}) "
            .'OR linked_order.invoiceable_type IS NOT DISTINCT FROM payment_documents.invoiceable_type '
            .'AND linked_order.invoiceable_id IS NOT DISTINCT FROM payment_documents.invoiceable_id)), 0) '
            .'ELSE payment_documents.amount END';
    }

    private function contractIdentitySql(
        string $documentAlias = 'payment_documents',
        string $actAlias = 'contract_performance_acts',
    ): string
    {
        $contractClass = str_replace("'", "''", Contract::class);

        return "CASE WHEN {$documentAlias}.invoiceable_type = '{$contractClass}' "
            ."THEN {$documentAlias}.invoiceable_id ELSE {$actAlias}.contract_id END";
    }
}
