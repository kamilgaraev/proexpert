<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use Illuminate\Support\Facades\DB;

final class EstimateFinanceCashSources
{
    public function __construct(private readonly EstimateFinanceCashSummary $summary, private readonly EstimateFinanceCashLedger $ledger) {}

    public function report(Estimate $estimate, array $contracts): array
    {
        $linkedIds = DB::table('contract_estimate_items')->where('estimate_id', $estimate->id)->pluck('contract_id')
            ->merge(DB::table('estimate_finance_allocations')->where('estimate_id', $estimate->id)->whereNotNull('contract_id')->pluck('contract_id'))->unique()->all();
        $contracts = array_intersect_key(array_column($contracts, null, 'id'), array_fill_keys($linkedIds, true));
        $contractIds = Contract::query()->whereIn('id', array_keys($contracts))->where('organization_id', $estimate->organization_id)
            ->where('project_id', $estimate->project_id)->pluck('id')->all();
        $acts = ContractPerformanceAct::query()->whereIn('contract_id', $contractIds)->where('project_id', $estimate->project_id)->pluck('contract_id', 'id')->all();
        $transactions = static fn ($query) => $query->where('organization_id', $estimate->organization_id)
            ->where(fn ($scope) => $scope->where('project_id', $estimate->project_id)->orWhereNull('project_id'))
            ->where('status', PaymentTransactionStatus::COMPLETED->value);
        $paymentDocuments = PaymentDocument::query()->where('organization_id', $estimate->organization_id)->where('project_id', $estimate->project_id)
            ->where(fn ($query) => $query->where(fn ($direct) => $direct->where('invoiceable_type', Contract::class)->whereIn('invoiceable_id', $contractIds))
                ->orWhere(fn ($byAct) => $byAct->where('invoiceable_type', ContractPerformanceAct::class)->whereIn('invoiceable_id', array_keys($acts))))
            ->where(fn ($query) => $query->whereHas('transactions', $transactions)->orWhere('paid_amount', '>', 0))
            ->with(['transactions' => fn ($query) => $transactions($query)->orderBy('id')])->orderBy('id')->get();
        $sources = [];
        $documents = [];
        foreach ($paymentDocuments as $document) {
            $contractId = $document->invoiceable_type === Contract::class ? (int) $document->invoiceable_id : (int) $acts[$document->invoiceable_id];
            $side = $contracts[$contractId]['side'];
            $direction = $document->direction?->value;
            $documentSide = $direction === 'incoming' ? 'revenue' : ($direction === 'outgoing' ? 'cost' : 'unknown');
            $conflict = $side !== 'unknown' && $documentSide !== 'unknown' && $side !== $documentSide;
            $side = $conflict ? 'unknown' : $side;
            $amounts = [];
            foreach ($document->transactions as $transaction) {
                $currency = $transaction->currency ?: $document->currency;
                $amount = $transaction->amount === null ? null : FinanceDecimal::value($transaction->amount);
                if (! array_key_exists($currency, $amounts)) {
                    $amounts[$currency] = '0.00';
                }
                $amounts[$currency] = $amount === null ? null : ($amounts[$currency] === null ? null : FinanceDecimal::add($amounts[$currency], $amount));
                $sources[] = ['transaction_id' => (int) $transaction->id, 'document_id' => (int) $document->id, 'contract_id' => $contractId,
                    'act_id' => $document->invoiceable_type === ContractPerformanceAct::class ? (int) $document->invoiceable_id : null,
                    'date' => $transaction->transaction_date?->format('Y-m-d'), 'amount' => $amount, 'currency' => $currency, 'side' => $side,
                    'reverses_transaction_id' => $transaction->reverses_transaction_id, 'invoice_type' => $document->invoice_type?->value,
                    'direction_requires_review' => $conflict || $side === 'unknown'];
            }
            $documents[] = ['id' => (int) $document->id, 'contract_id' => $contractId, 'number' => $document->document_number,
                'date' => $document->document_date?->format('Y-m-d'), 'currency' => $document->currency, 'amount' => $document->amount,
                'recorded_paid_amount' => $document->paid_amount, 'confirmed_amounts' => $amounts, 'side' => $side,
                'invoice_type' => $document->invoice_type?->value, 'status' => $document->status?->value,
                'payment_history_missing' => $document->transactions->isEmpty() && FinanceDecimal::compare((string) $document->paid_amount, '0') > 0,
                'direction_requires_review' => $conflict || $side === 'unknown'];
        }

        return ['available' => true, 'scope' => 'linked_contracts', 'sources' => $sources, 'documents' => $documents,
            'summary' => $this->summary->calculate($sources), 'distribution' => $this->ledger->report($estimate, $sources)];
    }
}
