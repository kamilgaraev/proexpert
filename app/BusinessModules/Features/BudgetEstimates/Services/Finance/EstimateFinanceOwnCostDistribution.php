<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\FinanceInputValidation;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\AdvanceAccountTransaction;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EstimateFinanceOwnCostDistribution
{
    public function __construct(private readonly EstimateFinanceAccess $access, private readonly EstimateFinanceQuery $query,
        private readonly AuthorizationService $authorization) {}

    public function handle(User $actor, int $projectId, int $estimateId, array $input, bool $save): array
    {
        $this->access->estimate($actor, $projectId, $estimateId, true);
        $data = FinanceInputValidation::validate($input, SaveEstimateFinanceRequest::ownCostDistributionRules(! $save));
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $projectId, $estimateId, $data, $hash, $save): array {
            $estimate = Estimate::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)
                ->whereKey($estimateId)->lockForUpdate()->firstOrFail();
            $cost = DB::table('estimate_finance_own_costs')->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->where('key', $data['cost_key'])->lockForUpdate()->first();
            if (! $cost) {
                $this->invalid();
            }
            if ($cost->source_type === 'advance_expense' && ! $this->authorization->can($actor, 'advance_transactions.view', [
                'context_type' => 'project', 'project_id' => $projectId, 'organization_id' => (int) $actor->current_organization_id,
            ])) {
                throw new AuthorizationException;
            }
            $receipt = DB::table('estimate_finance_mutations')->where('estimate_id', $estimateId)->where('mutation_id', $data['mutation_id'])->first();
            if ($save && $receipt) {
                if ($receipt->request_hash !== $hash || (int) $receipt->actor_id !== (int) $actor->id) {
                    $this->conflict();
                }

                return ['revision' => (int) $receipt->revision, 'replayed' => true];
            }
            if ((int) $estimate->finance_revision !== (int) $data['revision'] || (int) $cost->version !== (int) $data['source_version']
                || $cost->source_hash !== $data['source_hash']) {
                $this->conflict();
            }
            if ($cost->status !== 'confirmed') {
                $this->invalid();
            }
            if ($cost->source_type === 'advance_expense') {
                $source = AdvanceAccountTransaction::query()->where('organization_id', $actor->current_organization_id)
                    ->where('project_id', $projectId)->whereKey($cost->advance_transaction_id)->lockForUpdate()->first();
                $snapshot = json_decode($cost->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $document = $source?->only(['id', 'organization_id', 'project_id', 'type', 'amount', 'reporting_status',
                    'approved_at', 'approved_by_user_id', 'document_number', 'document_date', 'description', 'cost_category_id']);
                if ($document === null || json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) != ($snapshot['document'] ?? null)) {
                    $this->conflict();
                }
            }
            $existing = DB::table('estimate_finance_own_cost_allocations')->where('own_cost_id', $cost->id)->orderBy('id')->lockForUpdate()->get()->keyBy('allocation_id');
            $allocations = EstimateFinanceAllocation::query()->where('organization_id', $actor->current_organization_id)->where('estimate_id', $estimateId)
                ->whereIn('key', array_column($data['lines'], 'allocation_key'))->get()->keyBy('key');
            $targets = $this->query->targets($estimate);
            $changed = [];
            $targetKeys = [];
            foreach ($data['lines'] as $line) {
                $allocation = $allocations->get($line['allocation_key']);
                if (! $allocation || $allocation->source !== 'own' || $allocation->side !== 'cost' || $allocation->contract_id !== null
                    || $allocation->currency !== $cost->currency) {
                    $this->invalid();
                }
                $targetKey = $allocation->resource_id ? 'r:'.$allocation->resource_id : 'i:'.$allocation->estimate_item_id;
                if (! isset($targets[$targetKey]) || $targets[$targetKey]['excluded']) {
                    $this->invalid();
                }
                $before = $existing->get($allocation->id);
                if ((int) $allocation->condition_version !== (int) $line['condition_version'] || (int) ($before?->version ?? 0) !== (int) $line['version']) {
                    $this->conflict();
                }
                if ($before && (int) $before->estimate_id !== $estimateId) {
                    $this->invalid();
                }
                $targetKeys[] = $targetKey;
                $changed[$allocation->id] = ['before' => $before, 'allocation' => $allocation, 'amount' => FinanceDecimal::value($line['amount'])];
            }
            $this->access->editContracts($actor, $estimate, $targetKeys, []);
            $available = $cost->amount;
            $availableNet = $cost->amount_without_vat;
            foreach ($existing as $allocationId => $row) {
                if (! isset($changed[$allocationId])) {
                    $available = FinanceDecimal::subtract($available, $row->amount);
                    $availableNet = $availableNet === null || $row->amount_without_vat === null ? null
                        : FinanceDecimal::subtract($availableNet, $row->amount_without_vat);
                }
            }
            $weights = [];
            $remaining = $available;
            foreach ($changed as $allocationId => $change) {
                $weights['a:'.$allocationId] = $change['amount'];
                $remaining = FinanceDecimal::subtract($remaining, $change['amount']);
            }
            $clearing = $changed !== [] && collect($changed)->every(fn (array $change) => FinanceDecimal::compare($change['amount'], '0') === 0);
            if (! $clearing && (FinanceDecimal::compare($remaining, '0') < 0 || ($availableNet !== null
                && (FinanceDecimal::compare($availableNet, '0') < 0 || FinanceDecimal::compare($availableNet, $available) > 0)))) {
                $this->invalid('own_cost_exceeded');
            }
            $weights['remaining'] = $remaining;
            $net = $clearing ? array_replace(array_fill_keys(array_keys($weights), '0.00'), ['remaining' => $availableNet])
                : ($availableNet === null ? array_fill_keys(array_keys($weights), null)
                : (FinanceDecimal::compare($available, '0') === 0 ? array_fill_keys(array_keys($weights), '0.00')
                    : FinanceDecimal::allocate($availableNet, $weights)));
            $previewLines = [];
            foreach ($changed as $allocationId => $change) {
                $previewLines[] = ['allocation_key' => $change['allocation']->key, 'amount' => $change['amount'],
                    'amount_without_vat' => $net['a:'.$allocationId], 'version' => (int) ($change['before']?->version ?? 0),
                    'condition_version' => (int) $change['allocation']->condition_version];
            }
            if (! $save) {
                return ['operation' => 'own_cost_distribution', 'revision' => (int) $estimate->finance_revision,
                    'cost_key' => $cost->key, 'source_version' => (int) $cost->version, 'source_hash' => $cost->source_hash,
                    'currency' => $cost->currency, 'distributed_amount' => FinanceDecimal::subtract($cost->amount, $remaining),
                    'remaining_amount' => $remaining, 'remaining_without_vat' => $net['remaining'], 'lines' => $previewLines];
            }
            $revision = (int) $estimate->finance_revision + 1;
            $writes = [];
            $history = [];
            foreach ($changed as $allocationId => $change) {
                $before = $change['before'];
                $row = ['key' => $before?->key ?? (string) Str::uuid(), 'own_cost_id' => $cost->id, 'estimate_id' => $estimateId,
                    'allocation_id' => $allocationId, 'amount' => $change['amount'], 'amount_without_vat' => $net['a:'.$allocationId],
                    'version' => (int) ($before?->version ?? 0) + 1, 'source_version' => (int) $cost->version,
                    'condition_version' => (int) $change['allocation']->condition_version, 'updated_by' => $actor->id,
                    'created_at' => $before?->created_at ?? now(), 'updated_at' => now()];
                $writes[] = $row;
                $history[$allocationId] = ['version' => $row['version'], 'mutation_id' => $data['mutation_id'], 'finance_revision' => $revision,
                    'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after' => json_encode($row, JSON_THROW_ON_ERROR),
                    'actor_id' => $actor->id, 'created_at' => now()];
            }
            foreach (array_chunk($writes, 500) as $batch) {
                DB::table('estimate_finance_own_cost_allocations')->upsert($batch, ['own_cost_id', 'allocation_id'],
                    ['amount', 'amount_without_vat', 'version', 'source_version', 'condition_version', 'updated_by', 'updated_at']);
            }
            $ids = DB::table('estimate_finance_own_cost_allocations')->where('own_cost_id', $cost->id)->where('estimate_id', $estimateId)->pluck('id', 'allocation_id');
            foreach ($history as $allocationId => &$entry) {
                $entry['own_cost_allocation_id'] = $ids[$allocationId];
            }
            unset($entry);
            foreach (array_chunk(array_values($history), 500) as $batch) {
                DB::table('estimate_finance_own_cost_allocation_versions')->insert($batch);
            }
            DB::table('estimates')->where('id', $estimateId)->update(['finance_revision' => $revision]);
            DB::table('estimate_finance_mutations')->insert(['estimate_id' => $estimateId, 'mutation_id' => $data['mutation_id'],
                'request_hash' => $hash, 'actor_id' => $actor->id, 'revision' => $revision,
                'changes' => json_encode(['operation' => 'own_cost_distribution', 'cost_key' => $cost->key, 'lines' => $previewLines], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);

            return ['revision' => $revision, 'replayed' => false];
        }, 3);
    }

    private function invalid(string $key = 'own_cost_invalid'): never
    {
        throw ValidationException::withMessages(['cost' => trans_message('estimate_finance.'.$key)]);
    }

    private function conflict(): never
    {
        throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
    }
}
