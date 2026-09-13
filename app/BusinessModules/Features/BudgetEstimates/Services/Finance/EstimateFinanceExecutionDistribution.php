<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\FinanceInputValidation;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EstimateFinanceExecutionDistribution
{
    public function __construct(private readonly EstimateFinanceAccess $access, private readonly EstimateFinanceQuery $query,
        private readonly EstimateFinanceExecutionSource $sources, private readonly EstimateFinanceExecutionQuantity $quantities) {}

    public function handle(User $actor, int $projectId, int $estimateId, array $input, bool $save): array
    {
        $this->access->estimate($actor, $projectId, $estimateId, true);
        if (! $this->access->canViewExecution($actor, $projectId)) {
            throw new AuthorizationException;
        }
        $data = FinanceInputValidation::validate($input, SaveEstimateFinanceRequest::executionRules(! $save));
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
            $act = ContractPerformanceAct::query()->whereKey($data['act_id'])->where('project_id', $projectId)
                ->whereHas('contract', fn ($builder) => $builder->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId))
                ->lockForUpdate()->firstOrFail();
            $nativeLines = $act->lines()->orderBy('id')->lockForUpdate()->get();
            if ($nativeLines->isEmpty()) {
                $act->completedWorks()->orderBy('completed_works.id')->lockForUpdate()->get();
            }
            $source = $this->sources->read($estimate, $act);
            if (isset($data['source_hash']) && ! hash_equals($source['source_hash'], $data['source_hash'])) {
                $this->conflict();
            }
            $allocations = EstimateFinanceAllocation::query()->where('organization_id', $actor->current_organization_id)->where('estimate_id', $estimateId)
                ->whereIn('key', array_column($data['lines'], 'allocation_key'))->orderBy('id')->lockForUpdate()->get()->keyBy('key');
            $existing = DB::table('estimate_finance_execution_allocations')->where('performance_act_id', $act->id)->orderBy('id')->lockForUpdate()->get()->keyBy('allocation_id');
            $targets = $this->query->targets($estimate);
            $targetKeys = [];
            $changes = [];
            foreach ($data['lines'] as $line) {
                $allocation = $allocations->get($line['allocation_key']);
                if (! $allocation || (int) $allocation->contract_id !== (int) $act->contract_id || $allocation->currency !== $source['currency']) {
                    $this->invalid();
                }
                $targetKey = $allocation->resource_id ? 'r:'.$allocation->resource_id : 'i:'.$allocation->estimate_item_id;
                if (! isset($targets[$targetKey]) || $targets[$targetKey]['excluded']) {
                    $this->invalid();
                }
                $before = $existing->get($allocation->id);
                if ((int) $line['version'] !== (int) ($before?->version ?? 0) || (int) $line['condition_version'] !== (int) $allocation->condition_version) {
                    $this->conflict();
                }
                $targetKeys[] = $targetKey;
                $amount = FinanceDecimal::value($line['amount']);
                $quantity = array_key_exists('quantity', $line) ? $line['quantity'] : $before?->quantity;
                if ($quantity !== null) {
                    $quantity = FinanceDecimal::value((string) $quantity, 8);
                }
                if (FinanceDecimal::compare($amount, '0') === 0 && ! array_key_exists('quantity', $line)) {
                    $quantity = '0.00000000';
                }
                $changes[$allocation->id] = ['before' => $before, 'allocation' => $allocation, 'amount' => $amount, 'quantity' => $quantity];
            }
            $this->access->editContracts($actor, $estimate, $targetKeys, [(int) $act->contract_id]);
            $this->quantities->assertAvailable($estimate, (int) $act->contract_id, (int) $act->id, $changes);
            $available = $source['amount_with_vat'];
            $availableNet = $source['amount_without_vat'];
            foreach ($existing as $allocationId => $row) {
                if ((int) $row->organization_id !== (int) $actor->current_organization_id || (int) $row->project_id !== $projectId || $row->currency !== $source['currency']) {
                    $this->invalid();
                }
                if (! isset($changes[$allocationId])) {
                    $available = FinanceDecimal::subtract($available, $row->amount_with_vat);
                    $availableNet = $availableNet === null || $row->amount_without_vat === null ? null : FinanceDecimal::subtract($availableNet, $row->amount_without_vat);
                }
            }
            $remaining = $available;
            $weights = [];
            foreach ($changes as $allocationId => $change) {
                $weights['a:'.$allocationId] = $change['amount'];
                $remaining = FinanceDecimal::subtract($remaining, $change['amount']);
            }
            if (FinanceDecimal::compare($remaining, '0') < 0 || ($availableNet !== null
                && (FinanceDecimal::compare($availableNet, '0') < 0 || FinanceDecimal::compare($availableNet, $available) > 0))) {
                $this->invalid();
            }
            $weights['remaining'] = $remaining;
            $net = $availableNet === null ? array_fill_keys(array_keys($weights), null)
                : (FinanceDecimal::compare($available, '0') === 0 ? array_fill_keys(array_keys($weights), '0.00') : FinanceDecimal::allocate($availableNet, $weights));
            $previewLines = [];
            foreach ($changes as $allocationId => $change) {
                $previewLines[] = ['allocation_key' => $change['allocation']->key, 'amount' => $change['amount'], 'amount_without_vat' => $net['a:'.$allocationId],
                    'quantity' => $change['quantity'], 'version' => (int) ($change['before']?->version ?? 0), 'condition_version' => (int) $change['allocation']->condition_version];
            }
            if (! $save) {
                return ['operation' => 'execution_distribution', 'revision' => (int) $estimate->finance_revision, 'act_id' => (int) $act->id,
                    'source_hash' => $source['source_hash'], 'currency' => $source['currency'], 'source_amount' => $source['amount_with_vat'],
                    'distributed_amount' => FinanceDecimal::subtract($source['amount_with_vat'], $remaining), 'remaining_amount' => $remaining,
                    'remaining_without_vat' => $net['remaining'], 'lines' => $previewLines];
            }
            $revision = (int) $estimate->finance_revision + 1;
            $writes = [];
            $history = [];
            foreach ($changes as $allocationId => $change) {
                $before = $change['before'];
                $row = ['key' => $before?->key ?? (string) Str::uuid(), 'organization_id' => (int) $actor->current_organization_id,
                    'project_id' => $projectId, 'estimate_id' => $estimateId, 'allocation_id' => $allocationId, 'performance_act_id' => (int) $act->id,
                    'currency' => $source['currency'], 'quantity' => $change['quantity'], 'amount_with_vat' => $change['amount'], 'amount_without_vat' => $net['a:'.$allocationId],
                    'version' => (int) ($before?->version ?? 0) + 1, 'condition_version' => (int) $change['allocation']->condition_version,
                    'source_hash' => $source['source_hash'], 'source_snapshot' => json_encode(array_diff_key($source, ['snapshot' => true]), JSON_THROW_ON_ERROR),
                    'condition_snapshot' => json_encode($change['allocation']->getAttributes(), JSON_THROW_ON_ERROR),
                    'updated_by' => $actor->id, 'created_at' => $before?->created_at ?? now(), 'updated_at' => now()];
                $writes[] = $row;
                $history[$allocationId] = ['version' => $row['version'], 'mutation_id' => $data['mutation_id'], 'finance_revision' => $revision,
                    'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after' => json_encode($row, JSON_THROW_ON_ERROR),
                    'actor_id' => $actor->id, 'created_at' => now()];
            }
            foreach (array_chunk($writes, 500) as $batch) {
                DB::table('estimate_finance_execution_allocations')->upsert($batch, ['performance_act_id', 'allocation_id'],
                    ['quantity', 'amount_with_vat', 'amount_without_vat', 'version', 'condition_version', 'source_hash', 'source_snapshot', 'condition_snapshot', 'updated_by', 'updated_at']);
            }
            $ids = DB::table('estimate_finance_execution_allocations')->where('performance_act_id', $act->id)->where('estimate_id', $estimateId)->pluck('id', 'allocation_id');
            foreach ($history as $allocationId => &$entry) {
                $entry['execution_allocation_id'] = $ids[$allocationId];
            }
            unset($entry);
            foreach (array_chunk(array_values($history), 500) as $batch) {
                DB::table('estimate_finance_execution_versions')->insert($batch);
            }
            DB::table('estimates')->where('id', $estimateId)->update(['finance_revision' => $revision]);
            DB::table('estimate_finance_mutations')->insert(['estimate_id' => $estimateId, 'mutation_id' => $data['mutation_id'], 'request_hash' => $requestHash,
                'actor_id' => $actor->id, 'revision' => $revision, 'changes' => json_encode(['operation' => 'execution_distribution', 'act_id' => $act->id, 'lines' => $previewLines], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);

            return ['revision' => $revision, 'replayed' => false];
        }, 3);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.execution_distribution_invalid')]);
    }

    private function conflict(): never
    {
        throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
    }
}
