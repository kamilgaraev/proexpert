<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EstimateFinanceHistory
{
    public function forCash(Estimate $estimate, int $afterId): array
    {
        $rows = DB::table('estimate_finance_cash_versions as history')
            ->join('estimate_finance_cash_allocations as ledger', 'ledger.id', '=', 'history.cash_allocation_id')
            ->join('estimate_finance_allocations as conditions', 'conditions.id', '=', 'ledger.allocation_id')
            ->join('payment_transactions as payment', 'payment.id', '=', 'ledger.payment_transaction_id')
            ->join('contracts', 'contracts.id', '=', 'conditions.contract_id')
            ->join('estimate_items as items', 'items.id', '=', 'conditions.estimate_item_id')
            ->leftJoin('estimate_item_resources as resources', fn ($join) => $join->on('resources.id', '=', 'conditions.resource_id')->on('resources.estimate_item_id', '=', 'items.id'))
            ->leftJoin('users as actors', 'actors.id', '=', 'history.actor_id')
            ->where('ledger.organization_id', $estimate->organization_id)->where('ledger.project_id', $estimate->project_id)->where('ledger.estimate_id', $estimate->id)
            ->where('conditions.organization_id', $estimate->organization_id)->where('conditions.estimate_id', $estimate->id)->where('items.estimate_id', $estimate->id)
            ->where('contracts.organization_id', $estimate->organization_id)->where('contracts.project_id', $estimate->project_id)
            ->where('payment.organization_id', $estimate->organization_id)->where(fn ($query) => $query->whereNull('payment.project_id')->orWhere('payment.project_id', $estimate->project_id))
            ->where('history.id', '>', $afterId)->orderBy('history.id')->limit(101)
            ->select('history.id', 'history.version', 'history.created_at', 'history.before', 'history.after', 'actors.name as actor_name',
                'ledger.key as distribution_key', 'payment.id as transaction_id', 'contracts.id as contract_id', 'contracts.number as contract_number')
            ->selectRaw('COALESCE(resources.name, items.name) AS title')->get();
        $page = $rows->take(100)->map(function (object $row): array {
            $entry = ['id' => (int) $row->id, 'version' => (int) $row->version, 'created_at' => $row->created_at,
                'actor_name' => $row->actor_name, 'distribution_key' => $row->distribution_key, 'title' => $row->title,
                'transaction_id' => (int) $row->transaction_id, 'contract_id' => (int) $row->contract_id, 'contract_number' => $row->contract_number];
            foreach (['before', 'after'] as $field) {
                $entry[$field] = $row->$field === null ? null : array_intersect_key(json_decode($row->$field, true, 512, JSON_THROW_ON_ERROR), array_flip(['amount', 'currency']));
            }

            return $entry;
        })->values()->all();

        return ['data' => $page, 'has_more' => $rows->count() > 100, 'next_cursor' => $rows->count() > 100 ? end($page)['id'] : null];
    }

    public function forOwnCost(User $actor, Estimate $estimate, string $costKey, int $afterId): array
    {
        $cost = DB::table('estimate_finance_own_costs')->where('organization_id', $estimate->organization_id)
            ->where('project_id', $estimate->project_id)->where('key', $costKey)->firstOrFail();
        if ($cost->source_type === 'advance_expense' && ! app(\App\Domain\Authorization\Services\AuthorizationService::class)
            ->can($actor, 'advance_transactions.view', ['context_type' => 'project', 'project_id' => (int) $estimate->project_id,
                'organization_id' => (int) $estimate->organization_id])) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        $rows = DB::table('estimate_finance_own_cost_versions as history')->leftJoin('users as actors', 'actors.id', '=', 'history.actor_id')
            ->where('history.own_cost_id', $cost->id)->where('history.id', '>', $afterId)->orderBy('history.id')->limit(101)
            ->get(['history.id', 'history.version', 'history.created_at', 'history.before', 'history.after', 'actors.name as actor_name']);
        $page = $rows->take(100)->map(function (object $row): array {
            $entry = ['id' => (int) $row->id, 'version' => (int) $row->version, 'created_at' => $row->created_at, 'actor_name' => $row->actor_name];
            foreach (['before', 'after'] as $field) {
                $entry[$field] = $row->$field === null ? null : array_intersect_key(json_decode($row->$field, true, 512, JSON_THROW_ON_ERROR),
                    array_flip(['expense_date', 'basis', 'currency', 'amount', 'amount_without_vat', 'vat_mode', 'vat_rate', 'status', 'cost_category_id']));
            }

            return $entry;
        })->values()->all();

        return ['data' => $page, 'has_more' => $rows->count() > 100, 'next_cursor' => $rows->count() > 100 ? end($page)['id'] : null];
    }

    public function record(User $actor, Estimate $estimate, string $mutationId, int $revision, array $before, array $keys): void
    {
        $previous = array_column($before, null, 'key');
        $current = EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)->whereIn('key', $keys)
            ->get()->keyBy('key');
        $rows = [];
        foreach (array_unique(array_merge(array_keys($previous), $keys)) as $key) {
            $old = $previous[$key] ?? null;
            $new = $current->get($key)?->attributesToArray();
            if ($old === null && $new === null) {
                continue;
            }
            $rows[] = [
                'organization_id' => $estimate->organization_id, 'estimate_id' => $estimate->id,
                'allocation_key' => $key, 'condition_version' => $new['condition_version'] ?? ((int) $old['condition_version'] + 1),
                'finance_revision' => $revision, 'mutation_id' => $mutationId,
                'action' => $new === null ? 'deleted' : ($old === null ? 'created' : 'updated'),
                'before' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
                'after' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
                'actor_id' => $actor->id, 'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('estimate_finance_condition_versions')->insert($chunk);
        }
    }

    public function forEstimate(Estimate $estimate, int $afterId = 0): array
    {
        $rows = DB::table('estimate_finance_condition_versions as history')->leftJoin('users as actor', 'actor.id', '=', 'history.actor_id')
            ->where('history.organization_id', $estimate->organization_id)->where('history.estimate_id', $estimate->id)
            ->where('history.id', '>', $afterId)->orderBy('history.id')->limit(101)->get(['history.*', 'actor.name as actor_name']);
        $hasMore = $rows->count() > 100;
        $page = $rows->take(100)->map(static function (object $row): array {
            $value = (array) $row;
            $value['before'] = $row->before === null ? null : json_decode($row->before, true, 512, JSON_THROW_ON_ERROR);
            $value['after'] = $row->after === null ? null : json_decode($row->after, true, 512, JSON_THROW_ON_ERROR);

            return $value;
        })->values()->all();

        $snapshots = array_map(static fn (array $entry): array => $entry['after'] ?? $entry['before'] ?? [], $page);
        $items = DB::table('estimate_items')->where('estimate_id', $estimate->id)
            ->whereIn('id', array_column($snapshots, 'estimate_item_id'))->pluck('name', 'id');
        $resources = DB::table('estimate_item_resources as resource')->join('estimate_items as item', 'item.id', '=', 'resource.estimate_item_id')
            ->where('item.estimate_id', $estimate->id)->whereIn('resource.id', array_column($snapshots, 'resource_id'))
            ->get(['resource.id', 'resource.estimate_item_id', 'resource.name'])->keyBy('id');
        $contracts = DB::table('contracts')->where('organization_id', $estimate->organization_id)->where('project_id', $estimate->project_id)
            ->whereIn('id', array_column($snapshots, 'contract_id'))->pluck('number', 'id');
        foreach ($page as $index => &$entry) {
            $snapshot = $snapshots[$index];
            $resource = $resources->get($snapshot['resource_id'] ?? 0);
            $entry['title'] = isset($snapshot['resource_id'])
                ? ($resource !== null && (int) $resource->estimate_item_id === (int) ($snapshot['estimate_item_id'] ?? 0) ? $resource->name : null)
                : $items->get($snapshot['estimate_item_id'] ?? 0);
            $entry['contract_number'] = $contracts->get($snapshot['contract_id'] ?? 0);
        }
        unset($entry);

        return ['data' => $page, 'has_more' => $hasMore, 'next_cursor' => $hasMore ? end($page)['id'] : null];
    }

    public function forExecution(Estimate $estimate, int $afterId = 0): array
    {
        $rows = DB::table('estimate_finance_execution_versions as history')
            ->join('estimate_finance_execution_allocations as fact', 'fact.id', '=', 'history.execution_allocation_id')
            ->join('estimate_finance_allocations as conditions', 'conditions.id', '=', 'fact.allocation_id')
            ->join('contract_performance_acts as acts', 'acts.id', '=', 'fact.performance_act_id')
            ->join('contracts', 'contracts.id', '=', 'acts.contract_id')
            ->join('estimate_items as items', 'items.id', '=', 'conditions.estimate_item_id')
            ->leftJoin('estimate_item_resources as resources', fn ($join) => $join->on('resources.id', '=', 'conditions.resource_id')
                ->on('resources.estimate_item_id', '=', 'items.id'))
            ->leftJoin('users as actors', 'actors.id', '=', 'history.actor_id')
            ->where('fact.organization_id', $estimate->organization_id)->where('fact.project_id', $estimate->project_id)->where('fact.estimate_id', $estimate->id)
            ->where('conditions.organization_id', $estimate->organization_id)->where('conditions.estimate_id', $estimate->id)
            ->where('items.estimate_id', $estimate->id)->whereColumn('conditions.contract_id', 'acts.contract_id')
            ->where('contracts.organization_id', $estimate->organization_id)->where('contracts.project_id', $estimate->project_id)
            ->where('acts.project_id', $estimate->project_id)->where('history.id', '>', $afterId)
            ->orderBy('history.id')->limit(101)
            ->select('history.id', 'history.version', 'history.finance_revision', 'history.actor_id', 'history.created_at', 'history.before', 'history.after',
                'actors.name as actor_name', 'fact.key as distribution_key', 'conditions.key as allocation_key', 'conditions.resource_id', 'conditions.estimate_item_id',
                'acts.id as act_id', 'acts.act_document_number as act_number', 'acts.status as act_status', 'contracts.id as contract_id', 'contracts.number as contract_number')
            ->selectRaw('COALESCE(resources.name, items.name) AS title')->get();
        $hasMore = $rows->count() > 100;
        $page = $rows->take(100)->map(function (object $row): array {
            return ['id' => (int) $row->id, 'version' => (int) $row->version, 'finance_revision' => (int) $row->finance_revision,
                'created_at' => $row->created_at, 'actor' => ['id' => (int) $row->actor_id, 'name' => $row->actor_name],
                'distribution_key' => $row->distribution_key, 'allocation_key' => $row->allocation_key,
                'target_key' => $row->resource_id === null ? 'i:'.$row->estimate_item_id : 'r:'.$row->resource_id, 'title' => $row->title,
                'act' => ['id' => (int) $row->act_id, 'number' => $row->act_number, 'status' => $row->act_status],
                'contract' => ['id' => (int) $row->contract_id, 'number' => $row->contract_number],
                'before' => $this->executionSnapshot($row->before), 'after' => $this->executionSnapshot($row->after)];
        })->values()->all();

        return ['data' => $page, 'has_more' => $hasMore, 'next_cursor' => $hasMore ? end($page)['id'] : null];
    }

    private function executionSnapshot(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return array_intersect_key(json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            array_flip(['quantity', 'amount_with_vat', 'amount_without_vat', 'currency', 'condition_version']));
    }
}
