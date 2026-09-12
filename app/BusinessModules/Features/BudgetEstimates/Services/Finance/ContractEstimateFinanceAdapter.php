<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Contract;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ContractEstimateFinanceAdapter
{
    public function __construct(private readonly EstimateFinanceService $finance, private readonly EstimateFinanceQuery $query, private readonly EstimateFinanceAccess $access) {}

    public function updateVat(User $actor, Contract $contract, Estimate $estimate, bool $includeVat, ?string $rate = null, ?int $revision = null, ?string $mutationId = null): void
    {
        $this->access->estimate($actor, (int) $estimate->project_id, (int) $estimate->id, true);
        $mutationId ??= (string) Str::uuid();
        $commandHash = hash('sha256', json_encode(['coverage_vat', (int) $contract->id, $includeVat, $rate, $revision], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($actor, $contract, $estimate, $includeVat, $rate, $revision, $mutationId, $commandHash): void {
            $estimate = Estimate::query()->whereKey($estimate->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $contract->project_id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('estimate_finance_mutations')->where('estimate_id', $estimate->id)->where('mutation_id', $mutationId)->first();
            if ($receipt) {
                $changes = json_decode($receipt->changes, true, 512, JSON_THROW_ON_ERROR);
                if ((int) $receipt->actor_id !== (int) $actor->id || ($changes['adapter_command_hash'] ?? null) !== $commandHash) {
                    throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(trans_message('estimate_finance.conflict'));
                }
                $this->access->editContracts($actor, $estimate, [], [(int) $contract->id]);

                return;
            }
            $targets = $this->query->targets($estimate);
            $allocations = $this->query->allocations($estimate);
            $keys = [];
            foreach ($allocations as $allocation) {
                if ((int) $allocation['contract_id'] === (int) $contract->id && ! ($targets[$allocation['target_key']]['excluded'] ?? true)) {
                    $keys[$allocation['target_key']] = true;
                }
            }
            if ($keys === []) {
                throw ValidationException::withMessages(['estimate_id' => trans_message('estimate_finance.invalid')]);
            }
            $lines = [];
            foreach ($allocations as $allocation) {
                if (! isset($keys[$allocation['target_key']])) {
                    continue;
                }
                $line = $this->input($allocation);
                if ((int) $allocation['contract_id'] === (int) $contract->id) {
                    $selectedRate = $includeVat ? ($rate ?? $allocation['vat_rate']) : null;
                    if (($includeVat && $selectedRate === null) || $allocation['amount_without_vat'] === null) {
                        throw ValidationException::withMessages(['vat_rate' => trans_message('estimate_finance.tax_conditions')]);
                    }
                    $line = array_replace($line, ['vat_mode' => $includeVat ? 'exclusive' : 'none',
                        'vat_rate' => $selectedRate, 'price_basis' => 'without_vat', 'method' => 'total',
                        'unit_price' => null, 'amount' => $allocation['amount_without_vat']]);
                }
                $lines[] = $line;
            }
            $this->finance->save($actor, (int) $estimate->project_id, (int) $estimate->id, [
                'mutation_id' => $mutationId, 'revision' => $revision ?? (int) $estimate->finance_revision,
                'target_keys' => array_keys($keys), 'lines' => $lines,
            ]);
            $receiptQuery = DB::table('estimate_finance_mutations')->where('estimate_id', $estimate->id)->where('mutation_id', $mutationId);
            $changes = json_decode($receiptQuery->value('changes'), true, 512, JSON_THROW_ON_ERROR);
            $changes['adapter_command_hash'] = $commandHash;
            $receiptQuery->update(['changes' => json_encode($changes, JSON_THROW_ON_ERROR)]);
        }, 3);
    }

    public function detach(User $actor, Contract $contract, Estimate $estimate, array $itemIds): void
    {
        $this->access->estimate($actor, (int) $estimate->project_id, (int) $estimate->id, true);
        DB::transaction(function () use ($actor, $contract, $estimate, $itemIds): void {
            $estimate = Estimate::query()->whereKey($estimate->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $contract->project_id)->lockForUpdate()->firstOrFail();
            $allocations = $this->query->allocations($estimate);
            $selected = array_fill_keys(array_map(static fn ($id): string => 'i:'.$id, $itemIds), true);
            $keys = [];
            foreach ($allocations as $allocation) {
                if ((int) $allocation['contract_id'] === (int) $contract->id && isset($selected['i:'.$allocation['estimate_item_id']])) {
                    $keys[$allocation['target_key']] = true;
                    $keys['i:'.$allocation['estimate_item_id']] = true;
                }
            }
            $this->access->editContracts($actor, $estimate, array_keys($keys), [(int) $contract->id]);
            if ($keys === []) {
                return;
            }
            $lines = [];
            foreach ($allocations as $allocation) {
                if (isset($keys[$allocation['target_key']]) && (int) $allocation['contract_id'] !== (int) $contract->id) {
                    $lines[] = $this->input($allocation);
                }
            }
            $this->finance->save($actor, (int) $estimate->project_id, (int) $estimate->id, [
                'mutation_id' => (string) Str::uuid(), 'revision' => (int) $estimate->finance_revision,
                'target_keys' => array_keys($keys), 'lines' => $lines,
                'confirm_resource_changes' => true,
            ]);
        }, 3);
    }

    public function attach(User $actor, Contract $contract, Estimate $estimate, array $itemIds, bool $includeVat = false, ?string $rate = null): void
    {
        $this->access->estimate($actor, (int) $estimate->project_id, (int) $estimate->id, true);
        DB::transaction(function () use ($actor, $contract, $estimate, $itemIds, $includeVat, $rate): void {
            $estimate = Estimate::query()->whereKey($estimate->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $contract->project_id)->lockForUpdate()->firstOrFail();
            $targets = $this->query->targets($estimate);
            $keys = EstimateFinanceSelection::rootKeys($targets, $itemIds);
            $this->access->editContracts($actor, $estimate, array_keys($keys), [(int) $contract->id]);
            $lines = [];
            $linked = [];
            foreach ($this->query->allocations($estimate) as $allocation) {
                if (isset($keys[$allocation['target_key']])) {
                    $lines[] = $this->input($allocation);
                    if ((int) $allocation['contract_id'] === (int) $contract->id) {
                        $linked[$allocation['target_key']] = true;
                    }
                }
            }
            $added = false;
            foreach (array_diff_key($keys, $linked) as $key => $_) {
                if ($includeVat && $rate === null) {
                    throw ValidationException::withMessages(['vat_rate' => trans_message('estimate_finance.tax_conditions')]);
                }
                $target = $targets[$key];
                $lines[] = ['key' => (string) Str::uuid(), 'target_key' => $key, 'source' => 'contract',
                    'contract_id' => (int) $contract->id, 'currency' => $contract->currency ?: 'RUB',
                    'quantity' => $target['quantity'], 'method' => 'total', 'amount' => $target['estimate_amount'],
                    'price_basis' => 'without_vat', 'vat_mode' => $includeVat ? 'exclusive' : 'none',
                    'vat_rate' => $includeVat ? $rate : null, 'composition_confirmed' => true];
                $added = true;
            }
            if ($added) {
                $this->finance->save($actor, (int) $estimate->project_id, (int) $estimate->id, [
                    'mutation_id' => (string) Str::uuid(), 'revision' => (int) $estimate->finance_revision,
                    'target_keys' => array_keys($keys), 'lines' => $lines,
                ]);
            }
        }, 3);
    }

    public function initialAmount(Estimate $estimate, array $itemIds, bool $includeVat = false, ?string $rate = null): string
    {
        return EstimateFinanceSelection::amount($this->query->targets($estimate), $itemIds, $includeVat, $rate);
    }
    private function input(array $allocation): array
    {
        $legacy = $allocation['legacy'] ?? false;
        $basis = $allocation['price_basis'];
        $line = ['key' => $legacy ? (string) Str::uuid() : $allocation['key'], 'target_key' => $allocation['target_key'],
            'source' => $allocation['source'], 'contract_id' => $allocation['contract_id'], 'currency' => $allocation['currency'],
            'quantity' => $allocation['quantity'], 'unit_price' => $allocation['unit_price'],
            'amount' => $basis === 'unknown' ? ($allocation['legacy_amount'] ?? null)
                : $allocation[$basis === 'with_vat' ? 'amount_with_vat' : 'amount_without_vat'],
            'price_basis' => $basis, 'vat_rate' => $allocation['vat_rate'],
            'method' => $allocation['method'] === 'unit' ? 'unit' : 'total',
            'composition_confirmed' => $allocation['composition_confirmed'], 'notes' => $allocation['notes'] ?? null];
        if ($legacy) {
            $line['legacy_link_id'] = $allocation['contract_estimate_item_id'];
        } else {
            $line['condition_version'] = $allocation['condition_version'];
        }
        $mode = EstimateFinanceTax::mode($allocation);
        if ($mode !== 'unknown' || $basis === 'unknown') {
            $line['vat_mode'] = $mode;
        }

        return $line;
    }
}
