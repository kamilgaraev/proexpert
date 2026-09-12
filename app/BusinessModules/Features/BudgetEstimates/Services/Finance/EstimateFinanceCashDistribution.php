<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\FinanceInputValidation;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EstimateFinanceCashDistribution
{
    public function __construct(private readonly EstimateFinanceAccess $access, private readonly EstimateFinanceQuery $query,
        private readonly EstimateFinanceCashSources $sources) {}

    public function handle(User $actor, int $projectId, int $estimateId, array $input, bool $save): array
    {
        $this->access->estimate($actor, $projectId, $estimateId, true);
        if (! $this->access->canViewCash($actor, $projectId)) {
            throw new AuthorizationException;
        }
        $data = FinanceInputValidation::validate($input, SaveEstimateFinanceRequest::cashRules(! $save));
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $projectId, $estimateId, $data, $requestHash, $save): array {
            $estimate = Estimate::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)
                ->whereKey($estimateId)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('estimate_finance_mutations')->where('estimate_id', $estimateId)->where('mutation_id', $data['mutation_id'])->first();
            if ($save && $receipt) {
                if ($receipt->request_hash !== $requestHash || (int) $receipt->actor_id !== (int) $actor->id) {
                    $this->conflict();
                }

                return ['revision' => (int) $receipt->revision, 'replayed' => true];
            }
            if ((int) $estimate->finance_revision !== (int) $data['revision']) {
                $this->conflict();
            }
            $transaction = DB::table('payment_transactions')->where('organization_id', $actor->current_organization_id)
                ->where(fn ($q) => $q->where('project_id', $projectId)->orWhereNull('project_id'))->where('id', $data['transaction_id'])->first();
            if (! $transaction) {
                $this->invalid();
            }
            $document = DB::table('payment_documents')->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)
                ->where('id', $transaction->payment_document_id)->lockForUpdate()->first();
            if (! $document) {
                $this->invalid();
            }
            $native = DB::table('payment_transactions')->where('payment_document_id', $document->id)->where('organization_id', $actor->current_organization_id)
                ->where(fn ($q) => $q->where('project_id', $projectId)->orWhereNull('project_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $report = $this->sources->report($estimate, $this->query->contracts($estimate));
            $source = array_column($report['sources'], null, 'transaction_id')[$data['transaction_id']] ?? null;
            if (! $source || $source['amount'] === null || $source['currency'] === '' || $source['direction_requires_review'] || $source['side'] === 'unknown') {
                $this->invalid();
            }
            $sourceHash = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
            if (isset($data['source_hash']) && $data['source_hash'] !== $sourceHash) {
                $this->conflict();
            }
            $allocations = EstimateFinanceAllocation::query()->where('organization_id', $actor->current_organization_id)->where('estimate_id', $estimateId)
                ->whereIn('key', array_column($data['lines'], 'allocation_key'))->get()->keyBy('key');
            $targets = $this->query->targets($estimate);
            $targetKeys = [];
            $ledger = DB::table('estimate_finance_cash_allocations')->whereIn('payment_transaction_id', $native->keys())->get();
            $current = $ledger->where('payment_transaction_id', $source['transaction_id'])->keyBy('allocation_id');
            $ledgerByTransaction = [];
            $refundTotals = [];
            foreach ($ledger as $row) {
                $ledgerByTransaction[$row->payment_transaction_id][$row->allocation_id] = $row->amount;
                $payment = $native->get($row->payment_transaction_id);
                if ($payment?->reverses_transaction_id !== null && $payment->status === 'completed') {
                    $refundTotals[$payment->reverses_transaction_id][$row->allocation_id] = FinanceDecimal::add(
                        $refundTotals[$payment->reverses_transaction_id][$row->allocation_id] ?? '0', $row->amount,
                    );
                }
            }
            $total = '0.00';
            foreach ($current as $row) {
                $total = FinanceDecimal::add($total, $row->amount);
            }
            $changes = [];
            foreach ($data['lines'] as $line) {
                $allocation = $allocations->get($line['allocation_key']);
                if (! $allocation || (int) $allocation->contract_id !== $source['contract_id'] || $allocation->currency !== $source['currency']) {
                    $this->invalid();
                }
                $key = $allocation->resource_id ? 'r:'.$allocation->resource_id : 'i:'.$allocation->estimate_item_id;
                if (! isset($targets[$key]) || $targets[$key]['excluded']) {
                    $this->invalid();
                }
                $targetKeys[] = $key;
                $before = $current->get($allocation->id);
                if ((int) $line['version'] !== (int) ($before?->version ?? 0) || (int) $line['condition_version'] !== (int) $allocation->condition_version) {
                    $this->conflict();
                }
                $amount = FinanceDecimal::value($line['amount']);
                $sign = FinanceDecimal::compare($source['amount'], '0');
                if (($sign >= 0 && FinanceDecimal::compare($amount, '0') < 0) || ($sign < 0 && FinanceDecimal::compare($amount, '0') > 0)) {
                    $this->invalid();
                }
                $this->guardRefund($source, $native->all(), $ledgerByTransaction, $refundTotals, (int) $allocation->id, $amount);
                $total = FinanceDecimal::add(FinanceDecimal::subtract($total, $before?->amount ?? '0'), $amount);
                $changes[] = ['before' => $before, 'allocation_id' => (int) $allocation->id, 'allocation_key' => $allocation->key, 'amount' => $amount];
            }
            $this->access->editContracts($actor, $estimate, $targetKeys, [$source['contract_id']]);
            $remaining = FinanceDecimal::subtract($source['amount'], $total);
            if ((FinanceDecimal::compare($source['amount'], '0') >= 0 && FinanceDecimal::compare($remaining, '0') < 0)
                || (FinanceDecimal::compare($source['amount'], '0') < 0 && FinanceDecimal::compare($remaining, '0') > 0)) {
                $this->invalid();
            }
            if (! $save) {
                return ['operation' => 'cash_distribution', 'revision' => (int) $estimate->finance_revision, 'source_hash' => $sourceHash,
                    'transaction_id' => $source['transaction_id'], 'currency' => $source['currency'], 'source_amount' => $source['amount'],
                    'distributed_amount' => $total, 'remaining_amount' => $remaining, 'lines' => $data['lines']];
            }
            $revision = (int) $estimate->finance_revision + 1;
            $writes = [];
            $versions = [];
            foreach ($changes as $change) {
                $before = $change['before'];
                $version = (int) ($before?->version ?? 0) + 1;
                $row = ['key' => $before?->key ?? (string) Str::uuid(), 'organization_id' => (int) $actor->current_organization_id,
                    'project_id' => $projectId, 'estimate_id' => $estimateId, 'allocation_id' => $change['allocation_id'],
                    'payment_transaction_id' => $source['transaction_id'], 'currency' => $source['currency'], 'amount' => $change['amount'],
                    'version' => $version, 'source_hash' => $sourceHash, 'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR),
                    'updated_by' => $actor->id, 'created_at' => $before?->created_at ?? now(), 'updated_at' => now()];
                $writes[] = $row;
                $versions[$change['allocation_id']] = ['version' => $version,
                    'mutation_id' => $data['mutation_id'], 'finance_revision' => $revision, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                    'after' => json_encode($row, JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'created_at' => now()];
            }
            foreach (array_chunk($writes, 500) as $batch) {
                DB::table('estimate_finance_cash_allocations')->upsert($batch, ['payment_transaction_id', 'allocation_id'],
                    ['amount', 'version', 'source_hash', 'source_snapshot', 'updated_by', 'updated_at']);
            }
            $savedIds = DB::table('estimate_finance_cash_allocations')->where('estimate_id', $estimateId)->where('payment_transaction_id', $source['transaction_id'])
                ->pluck('id', 'allocation_id');
            foreach ($versions as $allocationId => &$version) {
                $version['cash_allocation_id'] = $savedIds[$allocationId];
            }
            unset($version);
            foreach (array_chunk(array_values($versions), 500) as $batch) {
                DB::table('estimate_finance_cash_versions')->insert($batch);
            }
            DB::table('estimates')->where('id', $estimateId)->update(['finance_revision' => $revision]);
            DB::table('estimate_finance_mutations')->insert(['estimate_id' => $estimateId, 'mutation_id' => $data['mutation_id'],
                'request_hash' => $requestHash, 'actor_id' => $actor->id, 'revision' => $revision,
                'changes' => json_encode(['operation' => 'cash_distribution', 'transaction_id' => $source['transaction_id'], 'lines' => $data['lines']], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);

            return ['revision' => $revision, 'replayed' => false];
        }, 3);
    }

    private function guardRefund(array $source, array $native, array $ledger, array $refundTotals, int $allocationId, string $amount): void
    {
        $originalId = $source['reverses_transaction_id'] ?? $source['transaction_id'];
        $original = $native[$originalId] ?? null;
        if (! $original || $original->status !== 'completed' || $original->currency !== $source['currency']
            || FinanceDecimal::compare($original->amount, '0') < 0 || ($source['reverses_transaction_id'] !== null && FinanceDecimal::compare($source['amount'], '0') >= 0)) {
            $this->invalid();
        }
        $refunds = $refundTotals[$originalId][$allocationId] ?? '0';
        $paid = $source['reverses_transaction_id'] === null ? $amount
            : ($ledger[$originalId][$allocationId] ?? '0');
        if ($source['reverses_transaction_id'] !== null) {
            $refunds = FinanceDecimal::add(FinanceDecimal::subtract($refunds, $ledger[$source['transaction_id']][$allocationId] ?? '0'), $amount);
        }
        if (FinanceDecimal::compare(FinanceDecimal::add((string) $paid, (string) $refunds), '0') < 0) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.cash_distribution_invalid')]);
    }

    private function conflict(): never
    {
        throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
    }
}
